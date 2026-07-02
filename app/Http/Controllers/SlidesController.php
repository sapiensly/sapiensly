<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckDataResolver;
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

        $manifest = json_decode((string) $deck->body, true);
        abort_unless(is_array($manifest), 404);

        // Live bindings (chart data_source / metric value_source) refresh on
        // every open; failures leave the static fallback in place.
        $manifest = app(DeckDataResolver::class)->resolve($manifest, $request->user());

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
        ]);
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

        $deck = Document::forAccountContext($user)
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $url = URL::temporarySignedRoute('slides.shared', now()->addDays(30), [
            'document' => $deck->id,
            'org' => $user->organization_id,
            'uid' => $user->id,
        ]);

        return new JsonResponse(['url' => $url]);
    }

    /**
     * The read-only viewer behind a share link (no session): the same Present
     * page with the workspace affordances hidden.
     */
    public function shared(Request $request, string $document): Response
    {
        [$deckProps, $brandProps] = $this->loadSignedDeck($request, $document);

        return Inertia::render('slides/Present', [
            'deck' => $deckProps,
            'brand' => $brandProps,
            'shared' => true,
        ]);
    }

    /**
     * Resolve a signed (sessionless) deck request: authenticate the scope the
     * signature carries, pin the tenant context to it, load + live-resolve the
     * deck, and restore the previous context.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
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

            $manifest = json_decode((string) $deck->body, true);
            abort_unless(is_array($manifest), 404);

            $manifest = app(DeckDataResolver::class)->resolve($manifest, $owner);
            $brand = $owner->organization?->brandbook();

            return [
                ['id' => $deck->id, 'name' => $deck->name, 'manifest' => $manifest],
                ['accent' => $brand?->effectiveAccent(), 'logo_url' => $brand?->logoUrl],
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
