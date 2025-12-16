<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Requests\Document\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\Folder;
use App\Models\KnowledgeBase;
use App\Services\DocumentService;
use App\Services\FolderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documentService,
        protected FolderService $folderService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $folderId = $request->query('folder');
        $search = $request->query('search');

        // Get documents query
        $documentsQuery = Document::visibleTo($user)
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
            'visibilityOptions' => collect(Visibility::cases())->map(fn ($v) => [
                'value' => $v->value,
                'label' => $v->label(),
                'description' => $v->description(),
            ])->values()->all(),
            'canShareWithOrg' => $user->hasOrganization(),
            'canDeleteFolder' => $currentFolder?->isOwnedBy($user) ?? false,
        ]);
    }

    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load(['user:id,name', 'folder:id,name', 'knowledgeBases:id,name']);

        // Get temporary URL for download
        $temporaryUrl = $this->documentService->getTemporaryUrl($document);

        return Inertia::render('documents/Show', [
            'document' => $document,
            'temporaryUrl' => $temporaryUrl,
            'canEdit' => $document->isOwnedBy($request->user()),
            'visibilityOptions' => collect(Visibility::cases())->map(fn ($v) => [
                'value' => $v->value,
                'label' => $v->label(),
                'description' => $v->description(),
            ])->values()->all(),
            'canShareWithOrg' => $request->user()->hasOrganization(),
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
                return back()->withErrors(['folder_id' => 'Invalid folder.']);
            }
        }

        // Validate knowledge base access if provided
        $knowledgeBase = null;
        if ($request->knowledge_base_id) {
            $knowledgeBase = KnowledgeBase::find($request->knowledge_base_id);
            if (! $knowledgeBase || $knowledgeBase->user_id !== $user->id) {
                return back()->withErrors(['knowledge_base_id' => 'Invalid knowledge base.']);
            }
        }

        $document = $this->documentService->upload(
            file: $request->file('file'),
            user: $user,
            visibility: $visibility,
            name: $request->name,
            folderId: $request->folder_id
        );

        // Attach to knowledge base if provided
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
                return back()->withErrors(['folder_id' => 'Invalid folder.']);
            }
            $data['folder_id'] = $request->folder_id;
        }

        if ($request->has('visibility')) {
            $visibility = Visibility::from($request->visibility);
            $document = $this->documentService->updateVisibility($document, $visibility, $request->user());
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
            return back()->withErrors(['document' => 'Document file not found.']);
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
            return back()->withErrors(['folder_id' => 'Invalid folder.']);
        }

        $this->documentService->moveToFolder($document, $folder);

        return back();
    }
}
