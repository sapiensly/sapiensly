<?php

namespace App\Http\Controllers;

use App\Jobs\RunBuilderAiJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Record;
use App\Services\AiProviderService;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\WireframeImporter;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordQueryService;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Builder AI surface for an App. The user chats with Claude here; Claude can
 * read the manifest, validate drafts, and propose RFC 6902 patches. Patches
 * are NOT applied until the user clicks Approve in the diff dialog.
 */
class AppBuilderController extends Controller
{
    public function __construct(
        private BuilderAiService $builder,
        private AppManifestService $manifestService,
        private BlockDataResolver $blockData,
        private RecordQueryService $records,
        private TenantStorage $tenantStorage,
        private WireframeImporter $wireframes,
        private AiProviderService $aiProviders,
    ) {}

    /**
     * Chat-capable Claude models the tenant has enabled, for the Builder's model
     * picker. Restricted to Anthropic: the Builder's tool-use loop is designed
     * and tested against Claude, and the 32k max-tokens cap (BuilderAgent) is
     * tuned for Claude 4.x. Falls back to the Anthropic catalog when the DB
     * catalog is empty so the picker is never blank.
     *
     * @return list<array{id: string, label: string}>
     */
    private function chatModels(): array
    {
        $anthropic = $this->aiProviders->getFullCatalog()['anthropic'] ?? [];

        $models = collect($anthropic)
            ->filter(fn (array $m) => in_array('chat', $m['capabilities'] ?? [], true))
            ->map(fn (array $m) => ['id' => $m['id'], 'label' => $m['label']])
            ->values();

        if ($models->isEmpty()) {
            $models = collect(AiProviderService::MODEL_CATALOGS['anthropic'])
                ->filter(fn (array $m) => in_array('chat', $m['capabilities'] ?? [], true))
                ->map(fn (array $m) => ['id' => $m['id'], 'label' => $m['label']])
                ->values();
        }

        return $models->all();
    }

    public function show(Request $request, App $app): Response
    {
        $this->assertCanAccess($request, $app);

        $conversation = BuilderConversation::query()
            ->where('app_id', $app->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if ($conversation === null) {
            $conversation = $this->builder->startConversation($app, $request->user());
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        $preview = $this->buildPreview($app, $request->user(), $manifest, $request->query('page'));
        $schema = $this->buildSchema($app, $manifest);

        return Inertia::render('apps/Builder', [
            'app' => $app->only(['id', 'slug', 'name', 'description']),
            'models' => $this->chatModels(),
            'defaultModel' => BuilderAiService::defaultModel(),
            'manifest' => $manifest,
            'preview' => $preview,
            'schema' => $schema,
            'conversation' => [
                'id' => $conversation->id,
                'messages' => $conversation->messages->map(fn (BuilderMessage $m) => $this->messageDto($m)),
            ],
        ]);
    }

    /**
     * Assemble the payload the Schema tab needs: objects with system fields
     * annotated inline, live record counts per object, and the workflows that
     * fire on each object's lifecycle hooks. One DB query for the counts
     * (group-by), one walk of manifest.workflows — cheap.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array{objects: list<array<string, mixed>>, record_counts: array<string, int>, workflows_by_object: array<string, list<array<string, mixed>>>}|null
     */
    private function buildSchema(App $app, ?array $manifest): ?array
    {
        if ($manifest === null) {
            return null;
        }

        $objects = $manifest['objects'] ?? [];
        if ($objects === []) {
            return [
                'objects' => [],
                'record_counts' => [],
                'workflows_by_object' => [],
            ];
        }

        // One grouped count for the whole app — beats N COUNT(*) round-trips.
        $counts = Record::query()
            ->where('app_id', $app->id)
            ->selectRaw('object_definition_id, count(*) as c')
            ->groupBy('object_definition_id')
            ->pluck('c', 'object_definition_id')
            ->map(fn ($c) => (int) $c)
            ->all();

        // Bucket workflows by the object they hook into so the schema card
        // can show its automation neighbours at a glance.
        $workflowsByObject = [];
        foreach ($manifest['workflows'] ?? [] as $wf) {
            $triggerType = $wf['trigger']['type'] ?? null;
            $objectId = $wf['trigger']['object_id'] ?? null;
            if ($objectId === null) {
                continue;
            }
            $workflowsByObject[$objectId] ??= [];
            $workflowsByObject[$objectId][] = [
                'id' => $wf['id'],
                'name' => $wf['name'] ?? $wf['slug'],
                'trigger_type' => $triggerType,
            ];
        }

        return [
            'objects' => array_map(
                fn (array $o) => $this->annotateObjectWithSystemFields($o),
                $objects,
            ),
            'record_counts' => $counts,
            'workflows_by_object' => $workflowsByObject,
        ];
    }

    /**
     * Append the virtual system fields (sys_created_at, sys_updated_at) onto
     * an object's fields array so the schema viewer can list them alongside
     * user-declared fields. Marked with `system: true` so the UI can style
     * them differently.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function annotateObjectWithSystemFields(array $object): array
    {
        $object['system_fields'] = [
            RecordQueryService::systemField('sys_created_at'),
            RecordQueryService::systemField('sys_updated_at'),
        ];

        return $object;
    }

    /**
     * Build the data the runtime preview pane needs. Empty manifest, missing
     * pages, or a query for a page that doesn't exist all return null so the
     * client can show an empty state instead of crashing.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array{page: array<string, mixed>, pages: list<array<string, mixed>>, block_data: array<string, mixed>, objects: list<array<string, mixed>>, settings: array<string, mixed>}|null
     */
    private function buildPreview(App $app, $user, ?array $manifest, ?string $pageSlug): ?array
    {
        if ($manifest === null) {
            return null;
        }

        $pages = $manifest['pages'] ?? [];
        if ($pages === []) {
            return null;
        }

        $page = null;
        if ($pageSlug !== null) {
            foreach ($pages as $p) {
                if (($p['slug'] ?? null) === $pageSlug) {
                    $page = $p;
                    break;
                }
            }
        }
        $page ??= $pages[0];

        $context = [
            'current_user' => ['id' => $user->id, 'email' => $user->email],
            'params' => [],
        ];

        return [
            'page' => $page,
            'pages' => array_map(
                fn (array $p) => ['id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'icon' => $p['icon'] ?? null],
                $pages,
            ),
            'block_data' => $this->blockData->resolve($app, $page['blocks'] ?? [], $manifest, $context),
            'objects' => $manifest['objects'] ?? [],
            'settings' => $manifest['settings'] ?? [],
        ];
    }

    public function sendMessage(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:5000'],
            // Optional per-turn model override chosen from the Builder's model
            // picker — must be one of the tenant's enabled chat models.
            'model' => ['nullable', 'string', Rule::in(array_column($this->chatModels(), 'id'))],
            // Optional image attachment. Limited to common raster formats
            // Claude vision accepts. 5 MB matches the model's per-image cap
            // with headroom for multipart overhead.
            'attachment' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'],
        ]);

        $conversation = $this->loadConversation($app, $data['conversation_id'], $request->user()->id);

        // Persist the attachment first (if any) so the user message that's
        // about to be created can reference its path. Resolve the tenant
        // disk before doing the upload so we surface a clean 503 instead of
        // a half-written DB row when S3 isn't configured.
        $attachmentPath = null;
        $attachmentMime = null;
        $attachmentDisk = null;
        if ($request->hasFile('attachment')) {
            $attachmentDisk = $this->tenantStorage->diskName($app);
            $upload = $request->file('attachment');
            $ext = strtolower($upload->getClientOriginalExtension() ?: 'png');
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                $ext = 'png';
            }
            $relative = TenantPath::scope($app->organization_id, $app->user_id, 'builder_chat_attachments/'.$app->id.'/'.now()->format('Ymd_His').'_'.Str::random(8).'.'.$ext);
            Storage::disk($attachmentDisk)->putFileAs(
                dirname($relative),
                $upload,
                basename($relative),
            );
            $attachmentPath = $relative;
            $attachmentMime = $upload->getMimeType() ?: 'image/'.$ext;
        }

        // Persist the user turn + an assistant placeholder up front so the
        // client can render them immediately while the background job streams
        // the assistant reply via Reverb.
        BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['message'],
            'status' => 'none',
            'attachment_path' => $attachmentPath,
            'attachment_mime' => $attachmentMime,
            'attachment_disk' => $attachmentDisk,
        ]);

        $placeholder = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        RunBuilderAiJob::dispatch($placeholder->id, $data['message'], $attachmentPath, $attachmentDisk, $data['model'] ?? null);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->refresh()->messages->map(fn (BuilderMessage $m) => $this->messageDto($m))->all(),
            'latest_message_id' => $placeholder->id,
            'streaming' => true,
        ]);
    }

    /**
     * Archive the current active conversation and start a fresh one. Used by
     * the "New conversation" button when the user wants to drop accumulated
     * context (it can confuse Claude across unrelated topics).
     */
    /**
     * "Pedir revisión visual": the frontend captured a PNG of the runtime
     * preview via html2canvas and POSTs it here. We persist the screenshot to
     * the private disk, create a user turn that quotes the screenshot, and
     * fire RunBuilderAiJob with the attachment path so Claude reasons about
     * the image alongside the chat.
     */
    public function visualReview(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
            'page_slug' => ['nullable', 'string'],
            // 4 MB cap — frontend downscales to 1600px max + JPEG 0.85 quality
            // before posting, so a dense full-page preview comes in around
            // 200-600 KB. PNG kept on the allowlist so historical clients
            // still work, but JPEG is the default.
            'screenshot' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:4096'],
        ]);

        $conversation = $this->loadConversation($app, $data['conversation_id'], $request->user()->id);

        // Persist to the tenant's S3 disk under a builder_screenshots/ prefix
        // that we can sweep later. The path stays private — only the job
        // process reads it through StoredImage to feed Claude.
        $diskName = $this->tenantStorage->diskName($app);
        $upload = $request->file('screenshot');
        $ext = strtolower($upload->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $ext = 'jpg';
        }
        $filename = TenantPath::scope($app->organization_id, $app->user_id, 'builder_screenshots/'.$app->id.'/'.now()->format('Ymd_His').'_'.Str::random(8).'.'.$ext);
        Storage::disk($diskName)->putFileAs(
            dirname($filename),
            $upload,
            basename($filename),
        );

        $pageLabel = $data['page_slug'] ?? 'la página actual';
        // The prompt is intentionally bossy: in prior turns the model would
        // see an "incomplete" looking screenshot and decide to "finish the
        // app" by inventing entirely new objects/pages/workflows the user
        // never asked for. Visual review is a *review*, not a license to
        // expand scope — clarify hard limits in the prompt itself.
        $userText = "MODO REVISIÓN VISUAL — esto NO es una petición para construir nada nuevo. Adjunto un screenshot de cómo se ve {$pageLabel} en el runtime ahora mismo.\n\n"
            ."Tu tarea:\n"
            ."1) Describe brevemente qué bloques observas en la página.\n"
            ."2) Si ves bugs visibles SOBRE LO QUE YA EXISTE (datos faltantes en bloques que ya están, overflow, colores que chocan, layout roto, blocks vacíos cuando deberían tener datos), arréglalos con propose_change — describir un bug y no arreglarlo es spam.\n"
            ."3) Si recomiendas mejoras de look-and-feel sobre bloques existentes (espaciado, jerarquía, agrupación), aplícalas con propose_change.\n"
            ."4) Si todo se ve bien, dilo en una frase y termina.\n\n"
            ."REGLA DURA: NO agregues NUEVOS objetos, NUEVOS campos, NUEVAS páginas, NUEVOS modales, NUEVOS workflows ni NUEVAS funcionalidades que el usuario no haya pedido en turnos previos. Si la página luce 'incompleta' o le falta algo (ej. un formulario que tendría sentido pero no está), PREGÚNTAME qué quiero agregar antes de hacerlo — no asumas. La regla 'describe = arregla' aplica SOLO a bugs en lo existente, no a 'features faltantes' que crees imaginar.";

        BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userText,
            'status' => 'none',
        ]);

        $placeholder = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        // Use Sonnet 4.5 instead of the default Haiku for visual review.
        // Haiku tended to ignore the "don't add new features" hard scope
        // limit when the screenshot looked incomplete; Sonnet sticks to
        // the scope-limited prompt.
        RunBuilderAiJob::dispatch(
            $placeholder->id,
            $userText,
            $filename,
            $diskName,
            BuilderAiService::VISUAL_REVIEW_MODEL,
        );

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->refresh()->messages->map(fn (BuilderMessage $m) => $this->messageDto($m))->all(),
            'latest_message_id' => $placeholder->id,
            'streaming' => true,
        ]);
    }

    /**
     * "Importar desde wireframe": the user shows the AI an existing design
     * (uploaded screenshot, public URL like a Figma share or claude.ai
     * artifact, or a chunk of raw HTML) and asks Claude to reconstruct it
     * as a Sapiensly manifest. We assemble whatever evidence we managed to
     * collect — a screenshot + extracted text — and dispatch the same
     * RunBuilderAiJob with a special user message that frames the task.
     */
    public function wireframeImport(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
            // Exactly one of the three sources must be present; we cross-check below.
            'source' => ['required', 'string', 'in:image,url,html'],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'],
            'url' => ['nullable', 'string', 'url', 'max:2048'],
            'html' => ['nullable', 'string', 'max:200000'],
            'business_context' => ['nullable', 'string', 'max:1000'],
        ]);

        $conversation = $this->loadConversation($app, $data['conversation_id'], $request->user()->id);

        // Translate the chosen source into (a) optional image bytes that we
        // persist on the tenant S3 disk + (b) an extracted text dump that
        // becomes part of the user message Claude sees.
        $attachmentBytes = null;
        $attachmentMime = null;
        $extractedTitle = null;
        $extractedDescription = null;
        $extractedText = null;
        $extractedHtml = null;
        $sourceLabel = null;

        if ($data['source'] === 'image') {
            if (! $request->hasFile('image')) {
                throw new HttpException(422, 'An image file is required when source=image.');
            }
            $upload = $request->file('image');
            $attachmentBytes = (string) file_get_contents($upload->getRealPath());
            $attachmentMime = $upload->getMimeType() ?: 'image/png';
            $sourceLabel = 'uploaded screenshot ('.$upload->getClientOriginalName().')';
        } elseif ($data['source'] === 'url') {
            if (empty($data['url'])) {
                throw new HttpException(422, 'A URL is required when source=url.');
            }
            try {
                $parsed = $this->wireframes->fromUrl($data['url']);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => 'wireframe_url_failed', 'message' => $e->getMessage()], 422);
            }
            $extractedTitle = $parsed['title'];
            $extractedDescription = $parsed['description'];
            $extractedText = $parsed['text'];
            $extractedHtml = $parsed['cleaned_html'];
            $sourceLabel = 'URL ('.($parsed['source_url'] ?? $data['url']).')';

            if ($parsed['image_url'] !== null) {
                $download = $this->wireframes->downloadImage($parsed['image_url']);
                if ($download !== null) {
                    $attachmentBytes = $download['bytes'];
                    $attachmentMime = $download['mime'];
                }
            }
        } else { // html
            if (empty($data['html'])) {
                throw new HttpException(422, 'HTML content is required when source=html.');
            }
            $parsed = $this->wireframes->fromHtml($data['html']);
            $extractedTitle = $parsed['title'];
            $extractedDescription = $parsed['description'];
            $extractedText = $parsed['text'];
            $extractedHtml = $parsed['cleaned_html'];
            $sourceLabel = 'pasted HTML';
        }

        // Persist the attachment on S3 (if we got one). Resolving the disk
        // up front means we'll throw the same 503 as other upload paths if
        // S3 isn't configured.
        $attachmentPath = null;
        $attachmentDisk = null;
        if ($attachmentBytes !== null) {
            $attachmentDisk = $this->tenantStorage->diskName($app);
            $ext = match (true) {
                str_contains($attachmentMime ?? '', 'jpeg') => 'jpg',
                str_contains($attachmentMime ?? '', 'png') => 'png',
                str_contains($attachmentMime ?? '', 'webp') => 'webp',
                str_contains($attachmentMime ?? '', 'gif') => 'gif',
                default => 'png',
            };
            $attachmentPath = TenantPath::scope($app->organization_id, $app->user_id, 'builder_wireframes/'.$app->id.'/'.now()->format('Ymd_His').'_'.Str::random(8).'.'.$ext);
            Storage::disk($attachmentDisk)->put($attachmentPath, $attachmentBytes);
        }

        $businessContext = trim((string) ($data['business_context'] ?? ''));
        $userText = $this->buildWireframePrompt(
            sourceLabel: (string) $sourceLabel,
            businessContext: $businessContext,
            extractedTitle: $extractedTitle,
            extractedDescription: $extractedDescription,
            extractedText: $extractedText,
            extractedHtml: $extractedHtml,
            hasImage: $attachmentBytes !== null,
        );

        BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userText,
            'status' => 'none',
            'attachment_path' => $attachmentPath,
            'attachment_mime' => $attachmentMime,
            'attachment_disk' => $attachmentDisk,
        ]);

        $placeholder = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        RunBuilderAiJob::dispatch($placeholder->id, $userText, $attachmentPath, $attachmentDisk);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->refresh()->messages->map(fn (BuilderMessage $m) => $this->messageDto($m))->all(),
            'latest_message_id' => $placeholder->id,
            'streaming' => true,
        ]);
    }

    /**
     * Compose the user-facing message that frames the wireframe-import task.
     * Kept here (rather than in the system prompt) so the user sees exactly
     * what we asked Claude on their behalf — full transparency.
     */
    private function buildWireframePrompt(
        string $sourceLabel,
        string $businessContext,
        ?string $extractedTitle,
        ?string $extractedDescription,
        ?string $extractedText,
        ?string $extractedHtml,
        bool $hasImage,
    ): string {
        $lines = [];
        $lines[] = 'Quiero que reconstruyas la UI mostrada en este wireframe como un manifest de Sapiensly Apps.';
        $lines[] = '';
        $lines[] = 'Fuente: '.$sourceLabel.'.';
        if ($hasImage) {
            $lines[] = 'Adjunto la imagen del wireframe; revísala con cuidado.';
        }
        if ($businessContext !== '') {
            $lines[] = '';
            $lines[] = 'Contexto de mi negocio:';
            $lines[] = $businessContext;
        }
        if ($extractedTitle || $extractedDescription) {
            $lines[] = '';
            $lines[] = 'Metadatos del wireframe:';
            if ($extractedTitle) {
                $lines[] = 'Título: '.$extractedTitle;
            }
            if ($extractedDescription) {
                $lines[] = 'Descripción: '.$extractedDescription;
            }
        }
        if ($extractedHtml) {
            // The HTML excerpt is the richest signal we have — it preserves
            // tag hierarchy, Tailwind/CSS class names and semantic roles,
            // which together let the model infer layout, components AND
            // visual feel (colors, spacing) without us having to render
            // anything server-side.
            $lines[] = '';
            $lines[] = 'HTML estructural del wireframe (úsalo como fuente principal para inferir layout, jerarquía, componentes y look-and-feel a partir de las clases CSS/Tailwind):';
            $lines[] = '```html';
            $lines[] = $extractedHtml;
            $lines[] = '```';
        } elseif ($extractedText) {
            // Fall back to the plain-text dump only when we couldn't get
            // useful HTML — better than nothing for OCR-style wireframes.
            $lines[] = '';
            $lines[] = 'Texto visible del wireframe:';
            $lines[] = $extractedText;
        }
        $lines[] = '';
        $lines[] = 'Tu tarea:';
        $lines[] = '1) Identifica qué tipo de datos muestra el wireframe y propón los objetos (con campos) que harían falta.';
        $lines[] = '2) Identifica las páginas/pantallas y propón cómo estructurarlas.';
        $lines[] = '3) Para cada página, propón los bloques (table, form, chart, kanban, stat, card_grid, tabs, etc.) que mejor reproduzcan el layout. Mapea elementos semánticos del HTML al block más cercano: <table> → table, <form> → form, <nav>/sidebar → tabs o split_view, secciones repetidas con tarjetas → card_grid, KPIs grandes → stat o metric_grid. Si ves clases como bg-*, text-*, rounded-*, p-*, m-* etc., úsalas para decidir variantes y agrupaciones, pero no inventes campos que no aparecen en el HTML/imagen.';
        $lines[] = '';
        $lines[] = 'Empieza por lo más foundational: primero los objetos con propose_change, luego páginas + bloques en turnos siguientes. Si el wireframe es genérico (un CRM, un tracker, etc.) y mi contexto es escaso, pregúntame de qué se trata mi negocio antes de inventar nombres de campo.';

        return implode("\n", $lines);
    }

    /**
     * Stream the image attachment for a builder chat message. The route only
     * accepts authenticated users; we additionally re-check that the user
     * owns the parent conversation so a user can't peek into another
     * tenant's screenshots by guessing message IDs.
     */
    public function messageAttachment(Request $request, BuilderMessage $message)
    {
        $conversation = $message->conversation;
        if (! $conversation) {
            abort(404);
        }

        $app = App::query()->find($conversation->app_id);
        if (! $app) {
            abort(404);
        }

        $this->assertCanAccess($request, $app);

        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $message->attachment_path) {
            abort(404);
        }

        // Resolve the disk the file was originally stored on (recorded on
        // the row) so we read from the right bucket even if the tenant has
        // since been migrated to a different S3 disk.
        $diskName = $message->attachment_disk ?: $this->tenantStorage->diskName($app);
        $disk = $this->tenantStorage->diskFromName($diskName);
        if (! $disk->exists($message->attachment_path)) {
            abort(404);
        }

        return $disk->response(
            $message->attachment_path,
            basename($message->attachment_path),
            ['Content-Type' => $message->attachment_mime ?: 'application/octet-stream'],
        );
    }

    public function startNewConversation(Request $request, App $app): RedirectResponse
    {
        $this->assertCanAccess($request, $app);

        BuilderConversation::query()
            ->where('app_id', $app->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->update(['status' => 'archived']);

        $this->builder->startConversation($app, $request->user());

        return redirect()->route('apps.builder', $app);
    }

    public function approve(Request $request, App $app, BuilderMessage $message): RedirectResponse
    {
        $this->assertCanAccess($request, $app);
        $this->assertMessageBelongsToApp($message, $app);

        $this->builder->approveProposal($message, $request->user());

        return back()->with('success', 'Change applied — new manifest version created.');
    }

    /**
     * Undo a previously auto-applied Builder change. Bumps the App's
     * current_version_id back to the manifest that was current before this
     * message's patch landed and marks the message status='reverted'.
     */
    public function revert(Request $request, App $app, BuilderMessage $message): RedirectResponse
    {
        $this->assertCanAccess($request, $app);
        $this->assertMessageBelongsToApp($message, $app);

        $this->builder->revertMessage($message, $request->user());

        return back()->with('success', 'Change reverted.');
    }

    public function reject(Request $request, App $app, BuilderMessage $message): RedirectResponse
    {
        $this->assertCanAccess($request, $app);
        $this->assertMessageBelongsToApp($message, $app);

        $this->builder->rejectProposal($message);

        return back()->with('success', 'Proposal rejected.');
    }

    /**
     * Schema-tab drill-down: return the records of one object as JSON for the
     * inline table view. Reuses RecordQueryService so filtering/derived fields
     * behave identically to the runtime.
     *
     * Query params:
     *   `limit`         default 50, max 200
     *   `offset`        default 0
     *   `q`             full-text search across all string + long_text fields
     *                   (Postgres ILIKE — case-insensitive)
     *   `sort_field_id` field id to sort by; sys_created_at / sys_updated_at
     *                   are accepted. Default: sys_created_at
     *   `sort_dir`      asc | desc. Default: desc
     */
    public function objectRecords(Request $request, App $app, string $objectId): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null) {
            throw new HttpException(404, 'No manifest for this app yet.');
        }

        $object = null;
        foreach ($manifest['objects'] ?? [] as $o) {
            if ($o['id'] === $objectId) {
                $object = $o;
                break;
            }
        }
        if ($object === null) {
            throw new HttpException(404, "Object '{$objectId}' not found in manifest.");
        }

        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $offset = max(0, (int) $request->query('offset', 0));
        $q = trim((string) $request->query('q', ''));
        $sortFieldId = (string) $request->query('sort_field_id', 'sys_created_at');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Search filter: OR over every text-shaped field. Skip when there are
        // no searchable fields — saves a SQL roundtrip that would have
        // matched nothing anyway.
        $textFieldIds = [];
        foreach ($object['fields'] as $f) {
            if (in_array($f['type'], ['string', 'long_text'], true)) {
                $textFieldIds[] = $f['id'];
            }
        }
        $filter = null;
        if ($q !== '' && $textFieldIds !== []) {
            $filter = [
                'op' => 'or',
                'conditions' => array_map(
                    fn (string $id) => ['op' => 'contains', 'field_id' => $id, 'value' => $q],
                    $textFieldIds,
                ),
            ];
        } elseif ($q !== '' && $textFieldIds === []) {
            // User searched but there's nothing text-shaped to search — return
            // an explicitly empty result rather than the unfiltered set.
            return new JsonResponse([
                'object' => [
                    'id' => $object['id'],
                    'slug' => $object['slug'],
                    'name' => $object['name'],
                    'fields' => $object['fields'],
                ],
                'rows' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        }

        // Resolve sort: must be either a real field on this object or one of
        // the system fields. Anything else falls back to sys_created_at.
        $validSortIds = array_merge(
            array_column($object['fields'], 'id'),
            ['sys_created_at', 'sys_updated_at'],
        );
        if (! in_array($sortFieldId, $validSortIds, true)) {
            $sortFieldId = 'sys_created_at';
        }

        $context = [
            'current_user' => ['id' => $request->user()->id, 'email' => $request->user()->email],
            'params' => [],
        ];

        $queryArgs = [
            'object_id' => $objectId,
            'sort' => [['field_id' => $sortFieldId, 'direction' => $sortDir]],
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($filter !== null) {
            $queryArgs['filter'] = $filter;
        }

        $records = $this->records->query($app, $queryArgs, $manifest, $context);

        // Count must respect the same filter so paging math (showing X of Y)
        // matches what the user sees.
        $countArgs = ['object_id' => $objectId];
        if ($filter !== null) {
            $countArgs['filter'] = $filter;
        }
        $total = (int) $this->records->aggregate($app, $countArgs, 'count', null, $manifest, $context);

        return new JsonResponse([
            'object' => [
                'id' => $object['id'],
                'slug' => $object['slug'],
                'name' => $object['name'],
                'fields' => $object['fields'],
            ],
            'rows' => $records->map(fn (Record $r) => [
                'id' => $r->id,
                'data' => $r->data,
                'sys_created_at' => optional($r->created_at)->toIso8601String(),
                'sys_updated_at' => optional($r->updated_at)->toIso8601String(),
            ])->values()->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'q' => $q,
            'sort_field_id' => $sortFieldId,
            'sort_dir' => $sortDir,
        ]);
    }

    private function assertCanAccess(Request $request, App $app): void
    {
        abort_unless($app->isVisibleTo($request->user()), 403);
    }

    private function loadConversation(App $app, string $conversationId, int $userId): BuilderConversation
    {
        $conversation = BuilderConversation::query()
            ->where('id', $conversationId)
            ->where('app_id', $app->id)
            ->where('user_id', $userId)
            ->first();

        if ($conversation === null) {
            throw new HttpException(404, 'Conversation not found.');
        }

        return $conversation;
    }

    private function assertMessageBelongsToApp(BuilderMessage $message, App $app): void
    {
        if ($message->conversation->app_id !== $app->id) {
            throw new HttpException(404, 'Message not found for this app.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function messageDto(BuilderMessage $m): array
    {
        // Build an attachment URL the client can render as a thumbnail.
        // The route requires auth + conversation ownership; the controller
        // streams the file from the private disk.
        $attachmentUrl = null;
        if ($m->attachment_path) {
            $attachmentUrl = route('apps.builder.message.attachment', [
                'message' => $m->id,
            ]);
        }

        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'proposed_patch' => $m->proposed_patch,
            'change_summary' => $m->change_summary,
            'status' => $m->status,
            'applied_version_id' => $m->applied_version_id,
            'attachment_url' => $attachmentUrl,
            'attachment_mime' => $m->attachment_mime,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
