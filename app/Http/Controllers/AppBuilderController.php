<?php

namespace App\Http\Controllers;

use App\Ai\BuilderAgent;
use App\Jobs\RunBuilderAiJob;
use App\Models\App;
use App\Models\AppSetting;
use App\Models\AppVersion;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Record;
use App\Services\AiProviderService;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\BlockVisibilityFilter;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\WireframeImporter;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use App\Services\Records\AppDataOverview;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordQueryService;
use App\Services\Storage\TenantStorage;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Css\ScopedAppCss;
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
        private AppAccessResolver $accessResolver,
        private BlockVisibilityFilter $visibility,
        private AppDataOverview $dataOverview,
    ) {}

    /**
     * Chat-capable models the tenant has enabled, for the Builder's model picker.
     * The picker also gates the per-turn `model` override validation (see
     * `sendMessage`), so anything not listed here is rejected at the boundary.
     *
     * Anthropic-only by default: the Builder's tool-use loop is designed and
     * tested against Claude. A sysadmin can opt the whole platform into OpenRouter
     * chat models by setting `admin_v2.ai.builder.allow_openrouter` to "true"
     * (admin AI settings) — gated rather than open so a weaker model can't be
     * picked self-serve until it has been validated (see `builder:eval`). Caching
     * is automatic for OpenRouter chat models and per-model output caps live in
     * {@see BuilderAgent::maxTokens()}. Falls back to the static Anthropic
     * catalog when the DB catalog is empty so the picker is never blank.
     *
     * @return list<array{id: string, label: string}>
     */
    private function chatModels(): array
    {
        $catalog = $this->aiProviders->getFullCatalog();

        $drivers = ['anthropic'];
        if (AppSetting::getValue('admin_v2.ai.builder.allow_openrouter', 'false') === 'true') {
            $drivers[] = 'openrouter';
        }

        $models = collect($drivers)
            ->flatMap(fn (string $driver) => $catalog[$driver] ?? [])
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

        // Forward the URL query as runtime params so param-driven blocks behave
        // in the preview exactly as they do live (filter_bar pre-fill, a
        // {{params.id}} detail page, a cart shown only when {{params.order}} is
        // set). `page` is the builder's own page selector, not an app param.
        $previewParams = array_filter(
            $request->except('page'),
            fn ($v) => is_string($v) || is_array($v),
        );
        $preview = $this->buildPreview($app, $request->user(), $manifest, $request->query('page'), $previewParams);
        $schema = $this->buildSchema($app, $manifest);

        return Inertia::render('apps/Builder', [
            'app' => $app->only(['id', 'slug', 'name', 'description', 'kind']),
            'models' => $this->chatModels(),
            'defaultModel' => BuilderAiService::defaultModel(),
            'manifest' => $manifest,
            'preview' => $preview,
            'schema' => $schema,
            // The org Brandbook, so the design panel can offer "use brand".
            'brand' => $app->organization?->brandbook()->toArray(),
            'versions' => $this->buildVersions($app),
            'conversation' => [
                'id' => $conversation->id,
                'build_plan' => $conversation->build_plan,
                'messages' => $conversation->messages->map(fn (BuilderMessage $m) => $this->messageDto($m)),
            ],
        ]);
    }

    /**
     * The recent version history for the Layers explorer — a compact timeline of
     * what changed, newest first, with the current version flagged.
     *
     * @return list<array{id: string, version: int, summary: string|null, created_at: string|null, current: bool}>
     */
    private function buildVersions(App $app): array
    {
        return AppVersion::query()
            ->where('app_id', $app->id)
            ->orderByDesc('version_number')
            ->limit(30)
            ->get(['id', 'version_number', 'change_summary', 'created_at'])
            ->map(fn (AppVersion $v) => [
                'id' => $v->id,
                'version' => $v->version_number,
                'summary' => $v->change_summary,
                'created_at' => $v->created_at?->toIso8601String(),
                'current' => $v->id === $app->current_version_id,
            ])
            ->all();
    }

    /**
     * Assemble the payload the Schema tab needs: objects (system fields annotated
     * inline), live per-object record counts, the relation graph, and the
     * workflows that fire on each object's lifecycle hooks. Delegates to the
     * shared AppDataOverview so the builder, MCP and the in-app agent all read
     * the same digest.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array{objects: list<array<string, mixed>>, record_counts: array<string, int>, relations: list<array<string, mixed>>, workflows_by_object: array<string, list<array<string, mixed>>>}|null
     */
    private function buildSchema(App $app, ?array $manifest): ?array
    {
        return $this->dataOverview->full($app, $manifest);
    }

    /**
     * Build the data the runtime preview pane needs. Empty manifest, missing
     * pages, or a query for a page that doesn't exist all return null so the
     * client can show an empty state instead of crashing.
     *
     * The preview must render what the runtime renders, not the raw manifest:
     * it resolves the same access context, forwards the same URL params, and
     * filters blocks through the same {@see BlockVisibilityFilter} BEFORE
     * resolving their data — exactly like {@see AppRuntimeController}. Otherwise
     * the "Vista en vivo" shows blocks the runtime hides (role- or
     * expression-gated) and resolves data without the role's row filters /
     * hidden fields, so it diverges from the deployed app.
     *
     * @param  array<string, mixed>|null  $manifest
     * @param  array<string, mixed>  $params  forwarded URL query (runtime params)
     * @return array{page: array<string, mixed>, pages: list<array<string, mixed>>, block_data: array<string, mixed>, objects: list<array<string, mixed>>, settings: array<string, mixed>}|null
     */
    private function buildPreview(App $app, $user, ?array $manifest, ?string $pageSlug, array $params = []): ?array
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

        // Same access context the runtime computes (the builder author is usually
        // an admin → bypass, so they preview as themselves; a non-admin author's
        // role filters/hidden fields apply, matching what they'd see live).
        $access = $this->accessResolver->resolve($app, $manifest, $user);
        $context = [
            'current_user' => ['id' => $user->id, 'email' => $user->email],
            'params' => $params,
            '__access' => $access,
            // Preview a live connected object as the builder author — a per-user
            // OAuth MCP source reads with their token, matching the runtime.
            '__actor' => $user,
        ];

        // Drop blocks the role or a visibility expression hides BEFORE resolving
        // data — identical to the runtime, so a hidden block never shows in the
        // preview (and its data never gets resolved either).
        $page['blocks'] = $this->visibility->visibleBlocks($page['blocks'] ?? [], $access, $context);

        // Org Brandbook fills unset brand values (live fallback); the app wins.
        $settings = $app->organization !== null
            ? $app->organization->brandbook()->applyToAppSettings($manifest['settings'] ?? [])
            : ($manifest['settings'] ?? []);
        $settings['palette'] = ColorPalette::fromAccent(
            $settings['accent'] ?? OrganizationBrand::DEFAULT_ACCENT,
        );

        return [
            'page' => $page,
            'pages' => array_map(
                fn (array $p) => ['id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'icon' => $p['icon'] ?? null],
                $pages,
            ),
            'block_data' => $this->blockData->resolve($app, $page['blocks'] ?? [], $manifest, $context),
            'objects' => $manifest['objects'] ?? [],
            // Apply the org Brandbook as a live fallback so the preview matches
            // what the runtime renders (AppRuntimeController does the same).
            'settings' => $settings,
            // Author CSS scoped to the app surface — preview mirrors the runtime.
            'custom_css' => ScopedAppCss::compile($settings['custom_css'] ?? null),
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
            // Autonomous mode: when true, the builder keeps working the build
            // plan across turns on its own (up to a hard cap) until the plan is
            // done or a turn stops advancing it.
            'autonomous' => ['nullable', 'boolean'],
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

        // Seed the autonomous budget only when asked; a normal turn passes 0.
        $autonomousRemaining = ($data['autonomous'] ?? false) ? BuilderAiService::AUTONOMOUS_MAX_TURNS : 0;

        RunBuilderAiJob::dispatch($placeholder->id, $data['message'], $attachmentPath, $attachmentDisk, $data['model'] ?? null, $autonomousRemaining);

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

        // The engine's native `search` scans every text-shaped field (string,
        // long_text, single_select, …) and matches nothing when the object has
        // none — so paging math stays correct without a special-case branch.
        $queryArgs = [
            'object_id' => $objectId,
            'sort' => [['field_id' => $sortFieldId, 'direction' => $sortDir]],
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($q !== '') {
            $queryArgs['search'] = $q;
        }

        // Resolve relations inline so the table can show a readable label for a
        // belongs_to link (instead of the raw foreign-key id) and a child-list
        // summary for a has_many.
        $relationFieldIds = [];
        foreach ($object['fields'] as $f) {
            if (($f['type'] ?? null) === 'relation') {
                $relationFieldIds[] = $f['id'];
            }
        }
        if ($relationFieldIds !== []) {
            $queryArgs['expand'] = $relationFieldIds;
        }

        $result = $this->records->queryWithMeta($app, $queryArgs, $manifest, $context);
        $records = $result['records'];
        $total = $result['total'];

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
                'expanded' => $r->expanded,
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

    /**
     * Schema-tab quick aggregation: count / sum / avg / min / max over an
     * object, optionally grouped by a field (with a date bucket) and narrowed by
     * the same free-text search as the records view. Routes through
     * RecordQueryService so the numbers match the runtime exactly.
     *
     * Query params:
     *   `aggregation`  count | sum | avg | min | max (default count)
     *   `field_id`     required for sum/avg/min/max (the numeric/derived field)
     *   `group_by`     optional field id to break the result down by
     *   `bucket`       day | week | month | quarter | year (date group fields)
     *   `q`            optional free-text search (same as the records view)
     */
    public function objectAggregate(Request $request, App $app, string $objectId): JsonResponse
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

        $aggregation = (string) $request->query('aggregation', 'count');
        if (! in_array($aggregation, RecordQueryService::AGGREGATIONS, true)) {
            throw new HttpException(422, 'Invalid aggregation.');
        }

        $fieldId = $request->query('field_id');
        $fieldId = is_string($fieldId) && $fieldId !== '' ? $fieldId : null;
        $groupBy = $request->query('group_by');
        $groupBy = is_string($groupBy) && $groupBy !== '' ? $groupBy : null;
        $bucket = $request->query('bucket');
        $bucket = is_string($bucket) && $bucket !== '' ? $bucket : null;
        $q = trim((string) $request->query('q', ''));

        if ($aggregation !== 'count' && $fieldId === null) {
            throw new HttpException(422, 'field_id is required for every aggregation except count.');
        }

        $context = [
            'current_user' => ['id' => $request->user()->id, 'email' => $request->user()->email],
            'params' => [],
        ];
        $query = ['object_id' => $objectId];
        if ($q !== '') {
            $query['search'] = $q;
        }

        try {
            if ($groupBy !== null) {
                $groups = $this->records->groupedAggregate($app, $query, $aggregation, $fieldId, $groupBy, $bucket, $manifest, $context);

                return new JsonResponse([
                    'aggregation' => $aggregation,
                    'field_id' => $fieldId,
                    'group_by' => $groupBy,
                    'bucket' => $bucket,
                    'groups' => $groups,
                ]);
            }

            $value = $this->records->aggregate($app, $query, $aggregation, $fieldId, $manifest, $context);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        return new JsonResponse([
            'aggregation' => $aggregation,
            'field_id' => $fieldId,
            'value' => $value,
        ]);
    }

    /**
     * Apply a design change (accent colour, theme, font) from the builder's
     * design controls. Each provided key is patched onto the manifest's
     * `settings`, saved as a reversible version. Only these cosmetic keys are
     * touched — the data model and pages are never affected.
     */
    public function updateDesign(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $data = $request->validate([
            'accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme' => ['nullable', 'string', Rule::in(['light', 'dark'])],
            'font' => ['nullable', 'string', Rule::in(['sans', 'serif', 'rounded', 'mono'])],
        ]);

        $provided = array_filter(
            ['accent' => $data['accent'] ?? null, 'theme' => $data['theme'] ?? null, 'font' => $data['font'] ?? null],
            fn ($value) => $value !== null,
        );
        if ($provided === []) {
            abort(422, 'Provide at least one of: accent, theme, font.');
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if (! is_array($manifest)) {
            abort(404, 'App has no active manifest yet.');
        }

        // RFC 6902 won't auto-create the parent container, so add an empty
        // `settings` object first when the manifest somehow lacks one.
        $ops = [];
        if (! array_key_exists('settings', $manifest)) {
            $ops[] = ['op' => 'add', 'path' => '/settings', 'value' => (object) []];
        }
        foreach ($provided as $key => $value) {
            // `add` on an object member replaces it when present, adds it otherwise.
            $ops[] = ['op' => 'add', 'path' => '/settings/'.$key, 'value' => $value];
        }

        try {
            $version = $this->manifestService->applyPatch($app, $ops, $request->user(), 'Design updated (accent/theme/font) from the builder.');
        } catch (InvalidManifestException $e) {
            return response()->json([
                'error' => 'invalid_manifest',
                'message' => 'The design change did not pass validation.',
                'errors' => $e->result->errorsArray(),
            ], 422);
        }

        return response()->json([
            'version_id' => $version->id,
            'version_number' => $version->version_number,
            'settings' => $version->manifest['settings'] ?? [],
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
            'plan' => $m->plan,
            'integration_proposal' => $m->integration_proposal,
            'status' => $m->status,
            'applied_version_id' => $m->applied_version_id,
            'plan_step_ids' => $m->plan_step_ids,
            'attachment_url' => $attachmentUrl,
            'attachment_mime' => $m->attachment_mime,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
