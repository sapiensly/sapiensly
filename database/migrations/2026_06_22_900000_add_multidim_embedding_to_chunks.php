<?php

use App\Services\VectorStoreSchema;
use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds heterogeneous-dimension embedding support to knowledge_base_chunks.
 *
 * The original `embedding vector(1536)` column is HNSW-indexed and stays the
 * fast path for the dominant 1536-dim models. pgvector cannot HNSW-index a
 * column without a fixed dimension, and that index caps at 2000 dims — so a
 * knowledge base configured with a different-dimension model (e.g.
 * text-embedding-3-large at 3072) could never store its vectors at all.
 *
 * `embedding_alt vector` (no fixed dimension) stores any other dimension via an
 * exact cosine scan; `embedding_dimensions` records the stored dimension so
 * retrieval routes to the matching column and never compares vectors of
 * mismatched dimension. Mirrors {@see VectorStoreSchema} which
 * bootstraps the same shape on BYODB / global provider connections.
 *
 * Runs as the owner against the tenant schema.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('knowledge_base_chunks');

        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS embedding_alt vector");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS embedding_dimensions smallint");

        // Existing rows are all 1536-dim — the only dimension the column allowed.
        DB::statement("UPDATE {$qualified} SET embedding_dimensions = 1536 WHERE embedding IS NOT NULL AND embedding_dimensions IS NULL");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('knowledge_base_chunks');

        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS embedding_alt");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS embedding_dimensions");
    }
};
