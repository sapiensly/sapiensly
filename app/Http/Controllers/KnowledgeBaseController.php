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
use App\Services\Ai\AiCapabilities;
use App\Services\AiProviderService;
use App\Services\DocumentService;
use App\Services\EmbeddingService;
use App\Services\FolderService;
use App\Services\IngestionCostEstimator;
use App\Services\RetrievalService;
use App\Services\VectorStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\AnonymousAgent;

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
            'document_ids.*' => ['string', 'exists:tenant.documents,id'],
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

    public function reprocessDocument(
        Request $request,
        KnowledgeBase $knowledgeBase,
        Document $document,
        VectorStoreService $vectorStoreService,
    ): RedirectResponse {
        $this->authorize('view', $knowledgeBase);

        // Reset pivot status
        $document->knowledgeBases()->updateExistingPivot($knowledgeBase->id, [
            'embedding_status' => EmbeddingStatus::Pending->value,
            'error_message' => null,
            'updated_at' => now(),
        ]);

        // Delete existing chunks via the vector-store service so the write
        // routes to the KB's resolved connection.
        $vectorStoreService->deleteForDocumentInKnowledgeBase($knowledgeBase, $document->id);

        // Dispatch processing job
        ProcessDocumentForKnowledgeBase::dispatch($document, $knowledgeBase);

        return back()->with('success', __('Document reprocessing started.'));
    }

    /**
     * Single-KB QA used for testing/debugging retrieval: retrieve ONLY from this
     * knowledge base, answer strictly from that context, and return the answer
     * alongside retrieval diagnostics (chunks + scores, models, timings).
     */
    public function ask(
        Request $request,
        KnowledgeBase $knowledgeBase,
        RetrievalService $retrieval,
        AiProviderService $providers,
        AiCapabilities $capabilities,
    ): JsonResponse {
        $this->authorize('view', $knowledgeBase);

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $providers->applyRuntimeConfig($request->user());

        $query = $validated['query'];
        $topK = $validated['top_k'] ?? 6;

        // Retrieval — scoped to THIS knowledge base only.
        $startRetrieval = microtime(true);
        $result = $retrieval->retrieve($query, [$knowledgeBase->id], topK: $topK, threshold: 0.5);
        $retrievalMs = (microtime(true) - $startRetrieval) * 1000;

        $resultChunks = collect($result['chunks']);
        $reranked = $resultChunks->contains(fn ($c) => isset($c->rerank_score));

        $docNames = Document::query()
            ->whereIn('id', $resultChunks->pluck('document_id')->filter()->unique()->all())
            ->get(['id', 'original_filename', 'name'])
            ->mapWithKeys(fn ($d) => [$d->id => $d->original_filename ?? $d->name ?? $d->id]);

        $chunks = $resultChunks->map(fn ($c) => [
            'source' => $docNames[$c->document_id] ?? 'Unknown source',
            'similarity' => isset($c->distance) ? round(1 - (float) $c->distance, 4) : null,
            'rerank_score' => isset($c->rerank_score) ? round((float) $c->rerank_score, 4) : null,
            'snippet' => Str::limit(trim((string) $c->content), 280),
        ])->values();

        // Generation — answer strictly from the retrieved context.
        $context = (string) $result['context'];
        $system = 'You are a QA assistant for a single knowledge base. Answer the question using ONLY the '
            .'provided context. If the answer is not contained in the context, say the information is not in '
            .'this knowledge base. Never use outside knowledge.';
        $prompt = $context === ''
            ? "No context was retrieved from the knowledge base.\n\nQuestion: {$query}"
            : "Context from the knowledge base:\n\n{$context}\n\nQuestion: {$query}";

        $startGen = microtime(true);
        try {
            $answer = (string) (new AnonymousAgent($system, [], []))->prompt($prompt)->text;
        } catch (\Throwable $e) {
            $answer = 'Error generating answer: '.$e->getMessage();
        }
        $generationMs = (microtime(true) - $startGen) * 1000;

        return response()->json([
            'answer' => $answer,
            'retrieval' => [
                'chunk_count' => $result['chunk_count'],
                'reranked' => $reranked,
                'rerank_model' => $reranked ? ($capabilities->resolve('reranking')['model'] ?? null) : null,
                'embedding_model' => EmbeddingService::forKnowledgeBase($knowledgeBase)->getModel(),
                'chunks' => $chunks,
            ],
            'timing_ms' => [
                'retrieval' => round($retrievalMs, 1),
                'generation' => round($generationMs, 1),
                'total' => round($retrievalMs + $generationMs, 1),
            ],
        ]);
    }

    /**
     * Estimate the USD cost of ingesting a document into this knowledge base
     * before processing it (OCR + embeddings). Returns JSON for the UI preview.
     */
    public function estimateCost(
        Request $request,
        KnowledgeBase $knowledgeBase,
        Document $document,
        IngestionCostEstimator $estimator,
    ): JsonResponse {
        $this->authorize('view', $knowledgeBase);
        abort_unless($document->isVisibleTo($request->user()), 403);

        return response()->json(
            $estimator->estimateForDocument($document, $knowledgeBase)
        );
    }

    public function detachDocument(Request $request, KnowledgeBase $knowledgeBase, Document $document, DocumentService $documentService): RedirectResponse
    {
        $this->authorize('update', $knowledgeBase);

        $documentService->detachFromKnowledgeBase($document, $knowledgeBase);

        return back();
    }
}
