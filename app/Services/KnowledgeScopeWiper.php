<?php

namespace App\Services;

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseDocument;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the knowledge base + document + chunk state for a set of
 * organizations. Used when a database cloud provider is replaced, removed,
 * or first configured (F2: wipe-on-switch). The wipe is intentional and
 * destructive — it is only invoked after type-to-confirm in the UI.
 */
class KnowledgeScopeWiper
{
    public function __construct(
        private VectorStoreService $vectorStoreService,
    ) {}

    /**
     * Count the rows that would be wiped for the given organization ids.
     * Cheap enough to run inline during the save request so the UI can
     * surface an accurate warning before asking for confirmation.
     *
     * @param  array<int, string>  $organizationIds
     * @return array{knowledge_bases: int, documents: int, chunks: int, organizations: int}
     */
    public function countForOrganizations(array $organizationIds): array
    {
        if (empty($organizationIds)) {
            return [
                'knowledge_bases' => 0,
                'documents' => 0,
                'chunks' => 0,
                'organizations' => 0,
            ];
        }

        $kbs = KnowledgeBase::query()
            ->whereIn('organization_id', $organizationIds)
            ->get(['id', 'organization_id']);

        // Count chunks via the vector-store service so we read from whichever
        // connection each KB's chunks actually live on. If the resolved
        // connection is unreachable (misconfigured or network down), degrade
        // to zero rather than 500-ing the whole save flow — the operator can
        // still fix credentials and retry.
        $chunkCount = 0;
        foreach ($kbs as $kb) {
            try {
                $chunkCount += $this->vectorStoreService->chunkCount($kb);
            } catch (\Throwable $e) {
                Log::warning('knowledge_scope_chunk_count_failed', [
                    'knowledge_base_id' => $kb->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'knowledge_bases' => $kbs->count(),
            'documents' => Document::query()
                ->whereIn('organization_id', $organizationIds)
                ->count(),
            'chunks' => $chunkCount,
            'organizations' => count($organizationIds),
        ];
    }

    /**
     * Wipe all KBs, documents, chunks, and pivot entries for the given
     * organizations. Uses a single transaction so a partial failure leaves
     * the app DB in its original state. Tenant PG databases are not touched
     * here — F3+ will route chunk deletions to the resolved connection.
     *
     * @param  array<int, string>  $organizationIds
     */
    public function wipeForOrganizations(array $organizationIds, string $reason): void
    {
        if (empty($organizationIds)) {
            return;
        }

        $counts = $this->countForOrganizations($organizationIds);

        Log::warning('knowledge_scope_wipe', [
            'organization_ids' => $organizationIds,
            'reason' => $reason,
            'counts' => $counts,
        ]);

        // Step 1 — delete chunks via the vector-store service (routes per KB
        // to the right connection). Run this OUTSIDE the app DB transaction
        // because chunks may live on a different physical database.
        $kbs = KnowledgeBase::query()
            ->whereIn('organization_id', $organizationIds)
            ->get(['id', 'organization_id']);

        foreach ($kbs as $kb) {
            try {
                $this->vectorStoreService->deleteAllForKnowledgeBase($kb);
            } catch (\Throwable $e) {
                Log::warning('knowledge_scope_chunk_delete_failed', [
                    'knowledge_base_id' => $kb->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Step 2 — delete the app-DB rows in a single transaction. Chunks are
        // already gone so there's no FK cascade work left.
        DB::transaction(function () use ($organizationIds, $kbs) {
            $kbIds = $kbs->pluck('id')->all();

            if (! empty($kbIds)) {
                KnowledgeBaseDocument::query()
                    ->whereIn('knowledge_base_id', $kbIds)
                    ->delete();

                // Detach Document <-> KnowledgeBase pivot rows.
                DB::table('document_knowledge_base')
                    ->whereIn('knowledge_base_id', $kbIds)
                    ->delete();
            }

            KnowledgeBase::query()
                ->whereIn('organization_id', $organizationIds)
                ->forceDelete();

            Document::query()
                ->whereIn('organization_id', $organizationIds)
                ->forceDelete();
        });
    }

    /**
     * Determine which organizations are impacted by a save or destroy of the
     * given scope:
     *  - Tenant scope: the single tenant org.
     *  - Global scope: every org that does NOT have its own active database
     *    override, since they were all falling back to the global provider.
     *
     * @return array<int, string>
     */
    public function impactedOrganizationIdsForDatabaseScope(
        string $scope,
        ?Organization $organization = null,
    ): array {
        if ($scope === 'tenant') {
            return $organization ? [$organization->id] : [];
        }

        if ($scope === 'global') {
            $orgsWithOverride = CloudProvider::query()
                ->whereNotNull('organization_id')
                ->where('kind', 'database')
                ->where('status', 'active')
                ->whereIn('visibility', [Visibility::Organization, Visibility::Private])
                ->pluck('organization_id')
                ->all();

            return Organization::query()
                ->when(! empty($orgsWithOverride), fn ($q) => $q->whereNotIn('id', $orgsWithOverride))
                ->pluck('id')
                ->all();
        }

        return [];
    }
}
