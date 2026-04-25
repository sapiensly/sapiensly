<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Requests\Document\StoreInlineDocumentRequest;
use App\Http\Requests\Document\UpdateDocumentRequest;
use App\Http\Requests\Document\UpdateInlineDocumentRequest;
use App\Jobs\StreamDocumentLlmJob;
use App\Models\Document;
use App\Models\Folder;
use App\Models\KnowledgeBase;
use App\Services\AiProviderService;
use App\Services\DocumentService;
use App\Services\FolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documentService,
        protected FolderService $folderService,
        protected AiProviderService $aiProviderService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $folderId = $request->query('folder');
        $search = $request->query('search');

        // Get documents query
        $documentsQuery = Document::forAccountContext($user)
            ->with(['user:id,name', 'folder:id,name'])
            ->withCount('knowledgeBases');

        // Filter by folder (null = show all, otherwise filter by folder_id)
        if ($folderId !== null) {
            $documentsQuery->where('folder_id', $folderId);
        }

        // Search by name or original filename
        if ($search) {
            $documentsQuery->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('original_filename', 'ilike', "%{$search}%");
            });
        }

        $documents = $documentsQuery->latest()->paginate(24);

        // Get folder tree
        $folderTree = $this->folderService->getGroupedFolders($user);

        // Get current folder for breadcrumbs
        $currentFolder = $folderId ? Folder::find($folderId) : null;
        $breadcrumbs = $currentFolder ? $currentFolder->getAncestors()->push($currentFolder) : collect();

        return Inertia::render('documents/Index', [
            'documents' => $documents,
            'folderTree' => $folderTree,
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $breadcrumbs,
            'filters' => [
                'search' => $search ?? '',
            ],
            'documentTypes' => collect(DocumentType::cases())
                ->filter(fn ($type) => $type !== DocumentType::Url)
                ->map(fn ($type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->values()
                ->all(),
            'visibilityOptions' => collect(Visibility::cases())
                ->filter(fn ($v) => $v !== Visibility::Global)
                ->map(fn ($v) => [
                    'value' => $v->value,
                    'label' => $v->label(),
                    'description' => $v->description(),
                ])
                ->values()
                ->all(),
            'canShareWithOrg' => $user->hasOrganization(),
            'canDeleteFolder' => $currentFolder?->isOwnedBy($user) ?? false,
        ]);
    }

    /**
     * Stand-alone Create Document page. Same prop shape the legacy modal
     * received so the Vue form can be lifted wholesale — the only new
     * input is the optional `?folder=` query string used for pre-selecting
     * the destination folder when the user came from inside a folder.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();
        $folderId = $request->query('folder');

        $currentFolder = $folderId ? Folder::find($folderId) : null;
        if ($currentFolder && ! $currentFolder->isVisibleTo($user)) {
            $currentFolder = null;
            $folderId = null;
        }

        return Inertia::render('documents/Create', [
            'currentFolder' => $currentFolder,
            'currentFolderId' => $folderId,
            'inlineDocumentTypes' => collect(DocumentType::cases())
                ->filter(fn ($type) => $type->isInlineAuthorable())
                ->map(fn ($type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'extension' => $type->extension(),
                ])
                ->values()
                ->all(),
            'visibilityOptions' => collect(Visibility::cases())
                ->filter(fn ($v) => $v !== Visibility::Global)
                ->map(fn ($v) => [
                    'value' => $v->value,
                    'label' => $v->label(),
                    'description' => $v->description(),
                ])
                ->values()
                ->all(),
            'canShareWithOrg' => $user->hasOrganization(),
            'availableChatModels' => $this->aiProviderService->getReachableChatModels($user),
            'defaultChatModelId' => $this->resolveDefaultChatModelId($user),
        ]);
    }

    /**
     * Pick the first enabled chat model of the user's default AI provider,
     * or null if they have no default set yet. Used to pre-select the
     * model selector on the Create / Edit pages.
     */
    private function resolveDefaultChatModelId($user): ?string
    {
        $provider = $this->aiProviderService->getDefaultProvider($user);

        return $provider?->getChatModels()[0]['id'] ?? null;
    }

    /**
     * Kick off an AI generation stream for a new document. Does a fast
     * preflight (validation + provider presence + chat-model presence),
     * then dispatches a queued StreamDocumentLlmJob and returns a stream
     * id immediately. The frontend subscribes to the matching Reverb
     * channel and accumulates chunks as they arrive — which sidesteps
     * any HTTP / gateway timeouts on long artifact generations.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'prompt' => ['required', 'string', 'min:4', 'max:2000'],
            'modelId' => ['nullable', 'string', 'max:255'],
        ]);

        $type = DocumentType::tryFrom($validated['type']);
        if (! $type || ! $type->isInlineAuthorable()) {
            return response()->json([
                'success' => false,
                'message' => __('Unsupported document type for AI generation.'),
            ], 422);
        }

        if ($error = $this->preflightAiProvider($request->user())) {
            return $error;
        }

        $modelId = $this->resolveChatModel($request->user(), $validated['modelId'] ?? null);
        if ($modelId === null) {
            return response()->json([
                'success' => false,
                'message' => __('The selected model is not available in your account.'),
            ], 422);
        }

        return $this->dispatchDocumentStream(
            user: $request->user(),
            mode: 'generate',
            type: $type->value,
            instruction: $validated['prompt'],
            modelId: $modelId,
        );
    }

    /**
     * One conversational refinement turn for an artifact workbench. The
     * client sends the in-memory chat history, the current HTML, and a new
     * instruction; we return the updated HTML. No DB writes — the chat is
     * ephemeral until the user clicks Save, which goes through storeInline
     * (create) or updateInline (existing doc).
     */
    public function refine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:artifact'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:10000'],
            'instruction' => ['required', 'string', 'min:4', 'max:2000'],
            'currentBody' => ['required', 'string', 'max:10485760'],
            'modelId' => ['nullable', 'string', 'max:255'],
        ]);

        if ($error = $this->preflightAiProvider($request->user())) {
            return $error;
        }

        $modelId = $this->resolveChatModel($request->user(), $validated['modelId'] ?? null);
        if ($modelId === null) {
            return response()->json([
                'success' => false,
                'message' => __('The selected model is not available in your account.'),
            ], 422);
        }

        return $this->dispatchDocumentStream(
            user: $request->user(),
            mode: 'refine',
            type: $validated['type'],
            instruction: $validated['instruction'],
            currentBody: $validated['currentBody'],
            history: $validated['history'] ?? [],
            modelId: $modelId,
        );
    }

    /**
     * Resolve the effective chat model id for the request. If the client
     * asked for a specific one, validate it belongs to one of the user's
     * visible providers. Otherwise fall back to the default provider's
     * first chat model. Returns null when the requested model isn't
     * reachable so the caller can 422 the request.
     */
    private function resolveChatModel($user, ?string $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return $this->resolveDefaultChatModelId($user);
        }

        $available = collect($this->aiProviderService->getReachableChatModels($user))
            ->pluck('value')
            ->all();

        return in_array($requested, $available, true) ? $requested : null;
    }

    /**
     * Reject early if the caller has no default chat provider configured —
     * shared by generate() and refine(). Returns a 422 JsonResponse that
     * the caller can return directly, or null when the check passes.
     */
    private function preflightAiProvider($user): ?JsonResponse
    {
        $provider = $this->aiProviderService->getDefaultProvider($user);

        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => __('No AI provider is configured. Configure one in System → AI providers.'),
            ], 422);
        }

        if (! ($provider->getChatModels()[0]['id'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => __('The configured AI provider has no chat models enabled.'),
            ], 422);
        }

        return null;
    }

    /**
     * Mint a stream id, authorize the user against the Reverb channel via
     * a short-lived cache entry, dispatch the streaming job, and return
     * the id so the frontend can subscribe.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function dispatchDocumentStream(
        $user,
        string $mode,
        string $type,
        string $instruction,
        ?string $currentBody = null,
        array $history = [],
        ?string $modelId = null,
    ): JsonResponse {
        $streamId = 'docstream_'.Str::ulid();

        // The channel guard in routes/channels.php reads this entry to
        // prove the subscriber owns the stream. 15 minutes is ample — a
        // model rarely takes that long and the entry self-expires if the
        // user walks away mid-stream.
        Cache::put("document-stream:{$streamId}", $user->id, now()->addMinutes(15));

        StreamDocumentLlmJob::dispatch(
            userId: $user->id,
            streamId: $streamId,
            mode: $mode,
            type: $type,
            instruction: $instruction,
            currentBody: $currentBody,
            history: $history,
            modelId: $modelId,
        );

        return response()->json([
            'success' => true,
            'streamId' => $streamId,
        ]);
    }

    public function edit(Request $request, Document $document): Response
    {
        $this->authorize('update', $document);

        if (! $document->type->isInlineAuthorable()) {
            abort(404);
        }

        // Same hydration as show(): uploaded artifacts keep their content on
        // disk rather than in the body column, but the workbench expects a
        // non-null body to mount the visual/code editors.
        $body = $document->body;
        if ($body === null && $document->file_path !== null) {
            $body = $this->documentService->readFileBody($document);
        }

        return Inertia::render('documents/Edit', [
            'document' => [
                'id' => $document->id,
                'name' => $document->name,
                'body' => $body,
                'type' => $document->type->value,
                'keywords' => $document->keywords ?? [],
                'visibility' => $document->visibility->value,
                'folder_id' => $document->folder_id,
            ],
            'visibilityOptions' => collect(Visibility::cases())
                ->filter(fn ($v) => $v !== Visibility::Global)
                ->map(fn ($v) => [
                    'value' => $v->value,
                    'label' => $v->label(),
                    'description' => $v->description(),
                ])
                ->values()
                ->all(),
            'canShareWithOrg' => $request->user()->hasOrganization(),
            'availableChatModels' => $this->aiProviderService->getReachableChatModels($request->user()),
            'defaultChatModelId' => $this->resolveDefaultChatModelId($request->user()),
        ]);
    }

    public function updateInline(UpdateInlineDocumentRequest $request, Document $document): RedirectResponse
    {
        if (! $document->type->isInlineAuthorable()) {
            abort(404);
        }

        $this->documentService->updateInline(
            document: $document,
            name: $request->input('name'),
            body: $request->input('body'),
            keywords: $request->input('keywords'),
        );

        return to_route('documents.show', $document);
    }

    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load(['user:id,name', 'folder:id,name', 'knowledgeBases:id,name']);

        // Get temporary URL for download
        $temporaryUrl = $this->documentService->getTemporaryUrl($document);

        // For Artifacts uploaded as a file (file_path set, body null), hydrate
        // `body` on the fly so the frontend's artifact viewer/editor work the
        // same way they do for inline-authored artifacts. This is a view-only
        // hydration — we never persist the file contents to the DB column.
        if (
            $document->type === DocumentType::Artifact
            && $document->body === null
            && $document->file_path !== null
        ) {
            $document->body = $this->documentService->readFileBody($document);
        }

        return Inertia::render('documents/Show', [
            'document' => $document,
            'temporaryUrl' => $temporaryUrl,
            'canEdit' => $document->isOwnedBy($request->user()),
            'visibilityOptions' => collect(Visibility::cases())
                ->filter(fn ($v) => $v !== Visibility::Global)
                ->map(fn ($v) => [
                    'value' => $v->value,
                    'label' => $v->label(),
                    'description' => $v->description(),
                ])
                ->values()
                ->all(),
            'canShareWithOrg' => $request->user()->hasOrganization(),
            'publicUrl' => $document->isPublic()
                ? route('documents.public', $document->id)
                : null,
        ]);
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $user = $request->user();
        $visibility = Visibility::tryFrom($request->visibility) ?? Visibility::Private;

        // Validate folder access if provided
        if ($request->folder_id) {
            $folder = Folder::find($request->folder_id);
            if (! $folder || ! $folder->isVisibleTo($user)) {
                return back()->withErrors(['folder_id' => __('Invalid folder.')]);
            }
        }

        // Validate knowledge base access if provided
        $knowledgeBase = null;
        if ($request->knowledge_base_id) {
            $knowledgeBase = KnowledgeBase::find($request->knowledge_base_id);
            if (! $knowledgeBase || $knowledgeBase->user_id !== $user->id) {
                return back()->withErrors(['knowledge_base_id' => __('Invalid knowledge base.')]);
            }
        }

        $document = $this->documentService->upload(
            file: $request->file('file'),
            user: $user,
            visibility: $visibility,
            name: $request->name,
            folderId: $request->folder_id,
            keywords: $request->keywords
        );

        // Attach to knowledge base if provided
        if ($knowledgeBase) {
            $this->documentService->attachToKnowledgeBase($document, $knowledgeBase);

            return to_route('knowledge-bases.show', $knowledgeBase);
        }

        return to_route('documents.show', $document);
    }

    public function storeInline(StoreInlineDocumentRequest $request): RedirectResponse
    {
        $user = $request->user();
        $visibility = Visibility::tryFrom($request->visibility) ?? Visibility::Private;

        if ($request->folder_id) {
            $folder = Folder::find($request->folder_id);
            if (! $folder || ! $folder->isVisibleTo($user)) {
                return back()->withErrors(['folder_id' => __('Invalid folder.')]);
            }
        }

        $knowledgeBase = null;
        if ($request->knowledge_base_id) {
            $knowledgeBase = KnowledgeBase::find($request->knowledge_base_id);
            if (! $knowledgeBase || $knowledgeBase->user_id !== $user->id) {
                return back()->withErrors(['knowledge_base_id' => __('Invalid knowledge base.')]);
            }
        }

        $type = DocumentType::from($request->type);

        $document = $this->documentService->createInline(
            user: $user,
            type: $type,
            body: $request->body,
            name: $request->name,
            visibility: $visibility,
            folderId: $request->folder_id,
            keywords: $request->keywords,
        );

        if ($knowledgeBase) {
            $this->documentService->attachToKnowledgeBase($document, $knowledgeBase);

            return to_route('knowledge-bases.show', $knowledgeBase);
        }

        return to_route('documents.show', $document);
    }

    public function update(UpdateDocumentRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = [];

        if ($request->has('name')) {
            $data['name'] = $request->name;
        }

        if ($request->has('folder_id')) {
            $folder = $request->folder_id ? Folder::find($request->folder_id) : null;
            if ($request->folder_id && (! $folder || ! $folder->isVisibleTo($request->user()))) {
                return back()->withErrors(['folder_id' => __('Invalid folder.')]);
            }
            $data['folder_id'] = $request->folder_id;
        }

        if ($request->has('body')) {
            if (! $document->isInline()) {
                return back()->withErrors(['body' => __('Body edits are only allowed on inline documents.')]);
            }
            $data['body'] = (string) $request->body;
        }

        if ($request->has('visibility')) {
            $visibility = Visibility::from($request->visibility);
            try {
                $document = $this->documentService->updateVisibility($document, $visibility, $request->user());
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['visibility' => $e->getMessage()]);
            }
        }

        if (! empty($data)) {
            $document->update($data);
        }

        return to_route('documents.show', $document);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $folderId = $document->folder_id;

        $this->documentService->delete($document);

        return to_route('documents.index', $folderId ? ['folder' => $folderId] : []);
    }

    public function download(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('download', $document);

        $temporaryUrl = $this->documentService->getTemporaryUrl($document);

        if (! $temporaryUrl) {
            return back()->withErrors(['document' => __('Document file not found.')]);
        }

        return redirect()->away($temporaryUrl);
    }

    public function move(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('move', $document);

        $request->validate([
            'folder_id' => ['nullable', 'string', 'exists:folders,id'],
        ]);

        $folder = $request->folder_id ? Folder::find($request->folder_id) : null;

        if ($folder && ! $folder->isVisibleTo($request->user())) {
            return back()->withErrors(['folder_id' => __('Invalid folder.')]);
        }

        $this->documentService->moveToFolder($document, $folder);

        return back();
    }
}
