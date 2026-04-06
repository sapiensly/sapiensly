<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeBaseStatus;
use App\Enums\Visibility;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeBaseRequest;
use App\Http\Requests\KnowledgeBase\UpdateKnowledgeBaseRequest;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\DocumentService;
use App\Services\FolderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): Response
    {
        $knowledgeBases = KnowledgeBase::query()
            ->forAccountContext($request->user())
            ->withCount(['documents', 'attachedDocuments'])
            ->latest()
            ->paginate(12);

        // Add combined document count
        $knowledgeBases->getCollection()->transform(function ($kb) {
            $kb->total_documents_count = ($kb->documents_count ?? 0) + ($kb->attached_documents_count ?? 0);

            return $kb;
        });

        return Inertia::render('knowledge-bases/Index', [
            'knowledgeBases' => $knowledgeBases,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('knowledge-bases/Create', [
            'documentTypes' => collect(DocumentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function store(StoreKnowledgeBaseRequest $request): RedirectResponse
    {
        $user = $request->user();

        $knowledgeBase = KnowledgeBase::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'status' => KnowledgeBaseStatus::Pending,
            'config' => $request->config ?? [
                'chunk_size' => 1000,
                'chunk_overlap' => 200,
            ],
        ]);

        return to_route('knowledge-bases.show', $knowledgeBase);
    }

    public function show(Request $request, KnowledgeBase $knowledgeBase, FolderService $folderService): Response
    {
        $this->authorize('view', $knowledgeBase);

        $user = $request->user();

        // Get documents attached to this KB (using new Document model)
        $attachedDocumentIds = $knowledgeBase->attachedDocuments()->pluck('documents.id')->toArray();

        // Get all documents in user's current account context
        $availableDocuments = Document::forAccountContext($user)
            ->with(['folder:id,name'])
            ->get();

        return Inertia::render('knowledge-bases/Show', [
            'knowledgeBase' => $knowledgeBase->load(['documents', 'attachedDocuments']),
            'documentTypes' => collect(DocumentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
            'availableDocuments' => $availableDocuments,
            'attachedDocumentIds' => $attachedDocumentIds,
            'folders' => $folderService->getGroupedFolders($user),
            'visibilityOptions' => collect(Visibility::cases())->map(fn ($v) => [
                'value' => $v->value,
                'label' => $v->label(),
                'description' => $v->description(),
            ])->values()->all(),
            'canShareWithOrg' => $user->hasOrganization(),
        ]);
    }

    public function edit(Request $request, KnowledgeBase $knowledgeBase): Response
    {
        $this->authorize('update', $knowledgeBase);

        return Inertia::render('knowledge-bases/Edit', [
            'knowledgeBase' => $knowledgeBase,
        ]);
    }

    public function update(UpdateKnowledgeBaseRequest $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $knowledgeBase->update([
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'config' => $request->config ?? $knowledgeBase->config,
        ]);

        return to_route('knowledge-bases.show', $knowledgeBase);
    }

    public function destroy(Request $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $this->authorize('delete', $knowledgeBase);

        $knowledgeBase->delete();

        return to_route('knowledge-bases.index');
    }

    public function attachDocuments(Request $request, KnowledgeBase $knowledgeBase, DocumentService $documentService): RedirectResponse
    {
        $this->authorize('update', $knowledgeBase);

        $request->validate([
            'document_ids' => ['required', 'array'],
            'document_ids.*' => ['string', 'exists:documents,id'],
        ]);

        $user = $request->user();

        foreach ($request->document_ids as $documentId) {
            $document = Document::find($documentId);

            // Verify user has access to the document
            if (! $document || ! $document->isVisibleTo($user)) {
                continue;
            }

            $documentService->attachToKnowledgeBase($document, $knowledgeBase);
        }

        return back();
    }

    public function reprocessDocument(Request $request, KnowledgeBase $knowledgeBase, Document $document): RedirectResponse
    {
        $this->authorize('view', $knowledgeBase);

        // Reset pivot status
        $document->knowledgeBases()->updateExistingPivot($knowledgeBase->id, [
            'embedding_status' => EmbeddingStatus::Pending->value,
            'error_message' => null,
            'updated_at' => now(),
        ]);

        // Delete existing chunks for this document in this KB
        $knowledgeBase->chunks()
            ->where('document_id', $document->id)
            ->delete();

        // Dispatch processing job
        ProcessDocumentForKnowledgeBase::dispatch($document, $knowledgeBase);

        return back()->with('success', __('Document reprocessing started.'));
    }

    public function detachDocument(Request $request, KnowledgeBase $knowledgeBase, Document $document, DocumentService $documentService): RedirectResponse
    {
        $this->authorize('update', $knowledgeBase);

        $documentService->detachFromKnowledgeBase($document, $knowledgeBase);

        return back();
    }
}
