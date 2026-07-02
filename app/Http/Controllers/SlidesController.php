<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Jobs\RefreshDeckJob;
use App\Jobs\RunSlideBuilderJob;
use App\Models\DeckVersion;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckDataResolver;
use App\Services\Slides\DeckEditor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Presentations (slide decks). A deck is a Document of type `deck` whose body
 * holds the validated deck manifest (see DeckValidator); the frontend renders
 * it deterministically. `/slides` lists the account's decks; `/p/{document}`
 * is the full-screen viewer.
 */
class SlidesController extends Controller
{
    public function index(Request $request): Response
    {
        $decks = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->with('user:id,name')
            ->reorder()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Document $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'theme' => (string) ($d->metadata['theme'] ?? 'executive'),
                'slide_count' => (int) ($d->metadata['slide_count'] ?? 0),
                'updated_at' => $d->updated_at?->toIso8601String(),
                'created_by' => $d->user?->name,
            ]);

        return Inertia::render('slides/Index', ['decks' => $decks]);
    }

    public function present(Request $request, string $document): Response
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $asOf = null;

        if ($request->query('v') !== null) {
            // Time travel: a historical version's manifest has its live data
            // BAKED at snapshot time — rendered as-is, read-only.
            $version = DeckVersion::query()
                ->where('document_id', $deck->id)
                ->where('version_number', (int) $request->query('v'))
                ->firstOrFail();
            $manifest = (array) $version->manifest;
            $asOf = $version->created_at?->toIso8601String();
        } else {
            $manifest = json_decode((string) $deck->body, true);
            abort_unless(is_array($manifest), 404);

            // Live bindings (chart data_source / metric value_source) refresh
            // on every open; failures leave the static fallback in place.
            $manifest = app(DeckDataResolver::class)->resolve($manifest, $request->user());
        }

        $brand = $request->user()?->organization?->brandbook();

        return Inertia::render('slides/Present', [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'manifest' => $manifest,
            ],
            'brand' => [
                'accent' => $brand?->effectiveAccent(),
                'logo_url' => $brand?->logoUrl,
            ],
            'as_of' => $asOf,
        ]);
    }

    /**
     * The deck's version history (Living Decks): newest first, with the AI
     * "what changed" summary per refresh.
     */
    public function versions(Request $request, string $document): JsonResponse
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $versions = DeckVersion::query()
            ->where('document_id', $deck->id)
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'cause', 'change_summary', 'created_by_user_id', 'created_at']);

        $authors = User::query()
            ->whereIn('id', $versions->pluck('created_by_user_id')->filter()->unique())
            ->pluck('name', 'id');

        return new JsonResponse([
            'versions' => $versions->map(fn (DeckVersion $v): array => [
                'id' => $v->id,
                'number' => $v->version_number,
                'cause' => $v->cause,
                'summary' => $v->change_summary,
                'author' => $v->created_by_user_id !== null ? $authors[$v->created_by_user_id] ?? null : null,
                'created_at' => $v->created_at?->toIso8601String(),
            ])->values(),
            'refresh' => (array) ($deck->metadata['refresh'] ?? []),
        ]);
    }

    /**
     * Restore a historical version: its SOURCE manifest (with live bindings)
     * becomes the current deck, recorded as a new `restore` version — history
     * is append-only, never rewound.
     */
    public function restoreVersion(Request $request, string $document, string $version): JsonResponse
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $target = DeckVersion::query()
            ->where('document_id', $deck->id)
            ->whereKey($version)
            ->firstOrFail();

        $manifest = (array) $target->source_manifest;
        $resolved = app(DeckDataResolver::class)->resolve($manifest, $request->user());

        app(DeckEditor::class)->persist(
            $deck,
            $manifest,
            $request->user(),
            'restore',
            "Restored version {$target->version_number}.",
            $resolved,
        );

        return new JsonResponse([
            'name' => $deck->refresh()->name,
            'manifest' => $manifest,
            'resolved' => $resolved,
        ]);
    }

    /**
     * Manual "Refresh now": queue the same job the scheduler uses. 202 —
     * the result lands in the version history (and live in an open Builder).
     */
    public function refreshNow(Request $request, string $document): JsonResponse
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        RefreshDeckJob::dispatch(
            $deck->id,
            $request->user()->organization_id,
            $request->user()->id,
            'manual_refresh',
        );

        return new JsonResponse(['queued' => true], 202);
    }

    /**
     * The Slide Builder: AI chat on the left, live deck preview + direct
     * editing on the right. `manifest` is the raw authored deck (what the
     * inspector edits); `resolved` has live data bindings folded in (what the
     * preview renders).
     */
    public function builder(Request $request, string $document): Response
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $manifest = json_decode((string) $deck->body, true);
        abort_unless(is_array($manifest), 404);

        $brand = $request->user()?->organization?->brandbook();

        return Inertia::render('slides/Builder', [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'manifest' => $manifest,
                'resolved' => app(DeckDataResolver::class)->resolve($manifest, $request->user()),
            ],
            'brand' => [
                'accent' => $brand?->effectiveAccent(),
                'logo_url' => $brand?->logoUrl,
            ],
            'messages' => array_values((array) ($deck->metadata['builder_chat'] ?? [])),
            'refresh' => (array) ($deck->metadata['refresh'] ?? []),
        ]);
    }

    /**
     * Direct (non-AI) edit from the Builder UI: slide operations, rename,
     * retheme — the same atomic DeckEditor path the AI and MCP use.
     */
    public function update(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'theme' => ['nullable', 'string'],
            'operations' => ['nullable', 'array', 'max:40'],
            'operations.*' => ['array'],
            // Living-Deck auto-refresh settings.
            'refresh' => ['nullable', 'array'],
            'refresh.frequency' => ['required_with:refresh', 'string', 'in:manual,hourly,daily,weekly,monthly'],
            'refresh.hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'refresh.narrative' => ['nullable', 'boolean'],
        ]);

        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $manifest = json_decode((string) $deck->body, true);
        abort_unless(is_array($manifest), 404);

        if (isset($validated['refresh'])) {
            $metadata = (array) $deck->metadata;
            $metadata['refresh'] = array_merge((array) ($metadata['refresh'] ?? []), [
                'frequency' => $validated['refresh']['frequency'],
                'hour' => (int) ($validated['refresh']['hour'] ?? 7),
                'narrative' => (bool) ($validated['refresh']['narrative'] ?? true),
            ]);
            $deck->update(['metadata' => $metadata]);
        }

        $hasEdit = ! empty($validated['operations'])
            || isset($validated['name'])
            || isset($validated['theme']);

        if ($hasEdit) {
            $editor = app(DeckEditor::class);
            [$next, $error] = $editor->apply(
                $manifest,
                array_values((array) ($validated['operations'] ?? [])),
                $validated['name'] ?? null,
                $validated['theme'] ?? null,
            );

            if ($next === null) {
                return new JsonResponse(['message' => $error], 422);
            }

            $resolved = app(DeckDataResolver::class)->resolve($next, $request->user());
            $editor->persist($deck, $next, $request->user(), 'edit', null, $resolved);
            $manifest = $next;
        } else {
            $resolved = app(DeckDataResolver::class)->resolve($manifest, $request->user());
        }

        return new JsonResponse([
            'name' => $deck->refresh()->name,
            'manifest' => $manifest,
            'resolved' => $resolved,
            'refresh' => (array) ($deck->metadata['refresh'] ?? []),
        ]);
    }

    /**
     * A Slide Builder chat message: queue the AI turn; the reply streams over
     * Reverb into the placeholder id returned here.
     */
    public function builderMessage(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
        ]);

        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $messageId = 'sbm_'.strtolower((string) Str::ulid());

        RunSlideBuilderJob::dispatch(
            $deck->id,
            $request->user()->id,
            $messageId,
            trim($validated['content']),
        );

        return new JsonResponse(['message_id' => $messageId], 202);
    }

    /**
     * Download the deck as a PDF. Renders the signed print page in headless
     * Chrome — the SAME components as the viewer at the same 1280×720 canvas,
     * so the export is pixel-faithful. Synchronous by design: a deck takes a
     * few seconds; if this ever grows past that, move it onto a queue.
     */
    public function export(Request $request, string $document): BinaryFileResponse
    {
        $user = $request->user();

        $deck = Document::forAccountContext($user)
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        // The print page runs unauthenticated in headless Chrome; the signed
        // URL carries the tenant scope and expires quickly.
        $printUrl = URL::temporarySignedRoute('slides.print', now()->addMinutes(10), [
            'document' => $deck->id,
            'org' => $user->organization_id,
            'uid' => $user->id,
        ]);

        $pdfPath = sys_get_temp_dir().'/deck-'.Str::random(12).'.pdf';

        $shot = Browsershot::url($printUrl)
            ->windowSize(1280, 720)
            ->waitForFunction('window.deckReady === true')
            ->timeout(90)
            ->showBackground()
            ->margins(0, 0, 0, 0)
            // 1280×720 CSS px at 96dpi — one slide per page, no letterboxing.
            ->paperSize(13.3333, 7.5, 'in')
            ->noSandbox()
            ->setNodeModulePath(base_path('node_modules'));

        if (is_string($node = config('services.node.binary')) && $node !== 'node') {
            $shot->setNodeBinary($node);
        }

        try {
            $shot->savePdf($pdfPath);
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'The PDF could not be generated: '.$e->getMessage());
        }

        return response()
            ->download($pdfPath, Str::slug($deck->name).'.pdf')
            ->deleteFileAfterSend(true);
    }

    /**
     * The print-layout page headless Chrome captures: every slide stacked at
     * the fixed 1280×720 canvas, one per PDF page. Signed-URL only (no
     * session), so the tenant scope comes from the signature's parameters.
     */
    public function print(Request $request, string $document): Response
    {
        [$deckProps, $brandProps] = $this->loadSignedDeck($request, $document);

        return Inertia::render('slides/Print', ['deck' => $deckProps, 'brand' => $brandProps]);
    }

    /**
     * Create (or refresh) a share link for people outside the tenant: a
     * 30-day signed URL to the read-only viewer. Anyone with the link can
     * watch the deck — treat it like attaching the deck to an email.
     */
    public function share(Request $request, string $document): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            // When set, the link is FROZEN to that version (what you sent is
            // what they will always see); omitted, the link tracks the living
            // deck with fresh data on every open.
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $deck = Document::forAccountContext($user)
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $params = [
            'document' => $deck->id,
            'org' => $user->organization_id,
            'uid' => $user->id,
        ];

        if (isset($validated['version'])) {
            DeckVersion::query()
                ->where('document_id', $deck->id)
                ->where('version_number', $validated['version'])
                ->firstOrFail();
            $params['v'] = $validated['version'];
        }

        $url = URL::temporarySignedRoute('slides.shared', now()->addDays(30), $params);

        return new JsonResponse(['url' => $url]);
    }

    /**
     * The read-only viewer behind a share link (no session): the same Present
     * page with the workspace affordances hidden.
     */
    public function shared(Request $request, string $document): Response
    {
        [$deckProps, $brandProps, $asOf] = $this->loadSignedDeck($request, $document);

        return Inertia::render('slides/Present', [
            'deck' => $deckProps,
            'brand' => $brandProps,
            'shared' => true,
            'as_of' => $asOf,
        ]);
    }

    /**
     * Resolve a signed (sessionless) deck request: authenticate the scope the
     * signature carries, pin the tenant context to it, load + live-resolve the
     * deck (or a frozen version when the signature pins `v`), and restore the
     * previous context.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null}
     */
    private function loadSignedDeck(Request $request, string $document): array
    {
        $owner = User::find((int) $request->query('uid'));
        abort_if($owner === null, 404);

        $org = $request->query('org') !== null ? (string) $request->query('org') : null;
        abort_if($org !== ($owner->organization_id ?: null), 404);

        $ctx = app(TenantContext::class);
        $previousOrg = $ctx->organizationId();
        $previousUid = $ctx->userId();
        $ctx->set($owner->organization_id, $owner->id);

        try {
            $deck = Document::forAccountContext($owner)
                ->where('type', DocumentType::Deck)
                ->findOrFail($document);

            $asOf = null;

            if ($request->query('v') !== null) {
                // Frozen share: the signed `v` pins the exact snapshot.
                $version = DeckVersion::query()
                    ->where('document_id', $deck->id)
                    ->where('version_number', (int) $request->query('v'))
                    ->firstOrFail();
                $manifest = (array) $version->manifest;
                $asOf = $version->created_at?->toIso8601String();
            } else {
                $manifest = json_decode((string) $deck->body, true);
                abort_unless(is_array($manifest), 404);
                $manifest = app(DeckDataResolver::class)->resolve($manifest, $owner);
            }

            $brand = $owner->organization?->brandbook();

            return [
                ['id' => $deck->id, 'name' => $deck->name, 'manifest' => $manifest],
                ['accent' => $brand?->effectiveAccent(), 'logo_url' => $brand?->logoUrl],
                $asOf,
            ];
        } finally {
            $ctx->set($previousOrg, $previousUid);
        }
    }

    public function destroy(Request $request, string $document): RedirectResponse
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $deck->delete();

        return redirect()->route('slides.index');
    }
}
