<?php

namespace App\Services;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unified read/write API for vector-store chunks. All chunk operations go
 * through this service so they can be routed to the knowledge base's
 * resolved database connection (tenant override -> global -> application
 * default) without callers needing to know where chunks physically live.
 *
 * F3 covers the write path (inserts, per-scope deletes, counts). F4 will
 * add the similarity-search read path.
 */
class VectorStoreService
{
    public const TABLE = 'knowledge_base_chunks';

    public function __construct(
        private CloudProviderService $cloudProviderService,
    ) {}

    // =========================================================================
    // Writes
    // =========================================================================

    /**
     * Insert a batch of chunks for a knowledge base. Each $chunk is expected
     * to carry `content`, `index`, `metadata`, and `embedding` keys (the same
     * shape the chunking + embedding services already emit). The service sets
     * `knowledge_base_id` and `embedding_model`, and — when provided — the
     * document / knowledge-base-document foreign keys.
     *
     * @param  array<int, array{content: string, index: int, metadata: array|null, embedding: mixed}>  $chunks
     */
    public function insertChunks(
        KnowledgeBase $kb,
        array $chunks,
        string $embeddingModel,
        ?string $documentId = null,
        ?string $knowledgeBaseDocumentId = null,
    ): void {
        if (empty($chunks)) {
            return;
        }

        $connection = $this->connectionFor($kb);
        $now = now();

        $rows = array_map(fn (array $chunk) => [
            'knowledge_base_id' => $kb->id,
            'document_id' => $documentId,
            'knowledge_base_document_id' => $knowledgeBaseDocumentId,
            'content' => $chunk['content'],
            'chunk_index' => $chunk['index'] ?? 0,
            'metadata' => isset($chunk['metadata']) ? json_encode($chunk['metadata']) : null,
            'embedding' => $this->serializeEmbedding($chunk['embedding'] ?? null),
            'embedding_model' => $embeddingModel,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunks);

        $connection->transaction(function () use ($connection, $rows) {
            $connection->table(self::TABLE)->insert($rows);
        });
    }

    /**
     * Remove all chunks belonging to a given Document within a specific KB.
     * Used when a document is detached or reprocessed.
     */
    public function deleteForDocumentInKnowledgeBase(KnowledgeBase $kb, string $documentId): int
    {
        return $this->connectionFor($kb)
            ->table(self::TABLE)
            ->where('knowledge_base_id', $kb->id)
            ->where('document_id', $documentId)
            ->delete();
    }

    /**
     * Remove all chunks belonging to a KnowledgeBaseDocument (legacy model).
     */
    public function deleteForKnowledgeBaseDocument(KnowledgeBase $kb, string $knowledgeBaseDocumentId): int
    {
        return $this->connectionFor($kb)
            ->table(self::TABLE)
            ->where('knowledge_base_id', $kb->id)
            ->where('knowledge_base_document_id', $knowledgeBaseDocumentId)
            ->delete();
    }

    /**
     * Remove every chunk attached to a knowledge base, regardless of document.
     * Used by the wipe-on-switch flow and by KB deletion.
     */
    public function deleteAllForKnowledgeBase(KnowledgeBase $kb): int
    {
        return $this->connectionFor($kb)
            ->table(self::TABLE)
            ->where('knowledge_base_id', $kb->id)
            ->delete();
    }

    /**
     * Remove every chunk pointing at a Document across every KB that document
     * is attached to. A document's chunks always live on the same connection
     * as the document's organization, so we can route with the document's
     * organization_id directly instead of walking each KB.
     */
    public function deleteAllForDocument(Document $document): int
    {
        return $this->connectionForOrganizationId($document->organization_id)
            ->table(self::TABLE)
            ->where('document_id', $document->id)
            ->delete();
    }

    // =========================================================================
    // Reads
    // =========================================================================

    public function chunkCount(KnowledgeBase $kb): int
    {
        return $this->connectionFor($kb)
            ->table(self::TABLE)
            ->where('knowledge_base_id', $kb->id)
            ->count();
    }

    /**
     * Retrieve the most similar chunks across one or more knowledge bases.
     * KB ids are grouped by their resolved database connection, each group is
     * queried independently (so tenants on their own PostgreSQL hit only their
     * own data), and the results are merged, sorted by distance, and capped at
     * $topK globally.
     *
     * On pgsql, vector similarity uses pgvector's cosine-distance operator
     * `<=>`. On other drivers (sqlite dev/test) the method returns chunks
     * scoped to the given KBs with `distance = 0.0` as a placeholder so call-
     * sites keep working in dev — they just won't see a semantic ranking.
     *
     * @param  array<int, string>  $knowledgeBaseIds
     * @param  array<int, float>  $queryEmbedding
     * @return Collection<int, KnowledgeBaseChunk>
     */
    public function searchSimilar(
        array $knowledgeBaseIds,
        array $queryEmbedding,
        int $topK = 5,
        float $threshold = 0.7,
    ): Collection {
        if (empty($knowledgeBaseIds) || empty($queryEmbedding)) {
            return collect();
        }

        // Group KB ids by the connection where their chunks live. Any KB ids
        // that no longer correspond to an existing row are silently dropped.
        $kbs = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->get(['id', 'organization_id']);

        $groups = [];
        foreach ($kbs as $kb) {
            $connectionName = $this->connectionFor($kb)->getName();
            $groups[$connectionName][] = $kb->id;
        }

        if (empty($groups)) {
            return collect();
        }

        $vectorString = '['.implode(',', $queryEmbedding).']';
        $distanceThreshold = 1 - $threshold;

        $results = collect();

        foreach ($groups as $connectionName => $kbIds) {
            $driver = DB::connection($connectionName)->getDriverName();

            $query = KnowledgeBaseChunk::on($connectionName)
                ->whereIn('knowledge_base_id', $kbIds)
                ->whereNotNull('embedding');

            if ($driver === 'pgsql') {
                $query
                    ->whereRaw('embedding <=> ? <= ?', [$vectorString, $distanceThreshold])
                    ->selectRaw('*, embedding <=> ? as distance', [$vectorString])
                    ->orderByRaw('embedding <=> ? ASC', [$vectorString]);
            } else {
                // Non-pgsql (dev/test): skip cosine distance and return
                // whatever chunks exist so callers keep working.
                $query->selectRaw('*, 0.0 as distance');
            }

            $results = $results->concat($query->limit($topK)->get());
        }

        if (count($groups) > 1) {
            $results = $results->sortBy('distance')->take($topK)->values();
        }

        return $results;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Resolve the connection where $kb's chunks live. Routed by the KB's
     * organization_id to the tenant provider, then global, then the
     * application default — in that order.
     */
    private function connectionFor(KnowledgeBase $kb): Connection
    {
        return $this->connectionForOrganizationId($kb->organization_id);
    }

    private function connectionForOrganizationId(?string $organizationId): Connection
    {
        $organization = $organizationId ? Organization::find($organizationId) : null;
        $provider = $this->cloudProviderService->resolveDatabase($organization);

        if ($provider === null) {
            return DB::connection();
        }

        return $this->cloudProviderService->buildConnection($provider);
    }

    /**
     * Normalize the embedding payload into the format each driver expects.
     * pgvector accepts a bracketed string like "[0.1,0.2,...]"; other drivers
     * (sqlite dev/test) get a JSON-encoded array they can round-trip without
     * interpretation.
     */
    private function serializeEmbedding(mixed $embedding): mixed
    {
        if ($embedding === null) {
            return null;
        }

        if (is_array($embedding)) {
            return '['.implode(',', $embedding).']';
        }

        return $embedding;
    }
}
