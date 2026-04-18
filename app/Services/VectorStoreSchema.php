<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Throwable;

/**
 * Creates, drops, and inspects the `knowledge_base_chunks` schema on an
 * arbitrary connection. Used to bootstrap tenant-scoped or global PostgreSQL
 * providers so embeddings can live outside the application database.
 *
 * The schema intentionally omits FK constraints to `knowledge_bases` and
 * `documents`: those rows live in the application database, so cross-DB FKs
 * would be unenforceable anyway.
 */
class VectorStoreSchema
{
    /**
     * PostgreSQL advisory-lock key used to serialize concurrent bootstraps on
     * the same physical database. Prevents two simultaneous requests from both
     * trying to create the table and racing on the vector column / index.
     */
    private const BOOTSTRAP_LOCK_KEY = 8234567123;

    public const VECTOR_DIMENSIONS = 1536;

    public const TABLE = 'knowledge_base_chunks';

    /**
     * Create the chunks table (idempotent) on the given connection. On pgsql
     * the vector column uses pgvector's `vector(1536)` type plus an HNSW
     * cosine index; on other drivers a plain text column is used so dev/test
     * environments keep working without the extension.
     *
     * @throws QueryException when the pgvector extension is missing on pgsql
     */
    public function ensureSchema(Connection $connection): void
    {
        $this->withAdvisoryLock($connection, function () use ($connection) {
            if ($connection->getSchemaBuilder()->hasTable(self::TABLE)) {
                return;
            }

            $this->createBaseTable($connection);
            $this->createEmbeddingColumn($connection);
        });
    }

    public function dropSchema(Connection $connection): void
    {
        $this->withAdvisoryLock($connection, function () use ($connection) {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('DROP INDEX IF EXISTS knowledge_base_chunks_embedding_idx');
            }

            $connection->getSchemaBuilder()->dropIfExists(self::TABLE);
        });
    }

    public function hasSchema(Connection $connection): bool
    {
        return $connection->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * Whether the pgvector extension is available on the target database.
     * Drivers other than pgsql always report true since they don't need it.
     */
    public function hasExtension(Connection $connection): bool
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return true;
        }

        try {
            $row = $connection->selectOne(
                "SELECT extname FROM pg_extension WHERE extname = 'vector'",
            );

            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Attempt to install the pgvector extension with `CREATE EXTENSION IF NOT
     * EXISTS`. Returns a result structure that callers can surface directly
     * in HTTP responses, including a manual SQL hint when the connecting
     * user lacks the CREATE privilege.
     *
     * @return array{success: bool, message: string, detail?: string, instructions?: string}
     */
    public function installExtension(Connection $connection): array
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return [
                'success' => true,
                'message' => __('Vector extension is not required for this database driver.'),
            ];
        }

        try {
            $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');

            return [
                'success' => true,
                'message' => __('pgvector extension is installed and ready.'),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => __('Could not install the pgvector extension automatically.'),
                'detail' => $e->getMessage(),
                'instructions' => __('Ask your database administrator to run: CREATE EXTENSION vector;'),
            ];
        }
    }

    /**
     * Run $callback inside a pgsql transaction guarded by an advisory lock so
     * concurrent bootstraps on the same physical database are serialized.
     * Non-pgsql drivers run the callback directly (no cross-process racing
     * concerns in dev/test).
     */
    private function withAdvisoryLock(Connection $connection, \Closure $callback): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            $callback();

            return;
        }

        $connection->transaction(function () use ($connection, $callback) {
            $connection->statement('SELECT pg_advisory_xact_lock(?)', [self::BOOTSTRAP_LOCK_KEY]);
            $callback();
        });
    }

    private function createBaseTable(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('knowledge_base_document_id', 36)->nullable();
            $table->string('document_id', 36)->nullable();
            $table->string('knowledge_base_id', 36);
            $table->text('content');
            $table->unsignedInteger('chunk_index');
            $table->json('metadata')->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamps();

            $table->index(['knowledge_base_id', 'chunk_index']);
            $table->index('document_id');
        });
    }

    private function createEmbeddingColumn(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(sprintf(
                'ALTER TABLE %s ADD COLUMN embedding vector(%d)',
                self::TABLE,
                self::VECTOR_DIMENSIONS,
            ));
            $connection->statement(
                'CREATE INDEX knowledge_base_chunks_embedding_idx ON knowledge_base_chunks USING hnsw (embedding vector_cosine_ops)',
            );

            return;
        }

        $connection->getSchemaBuilder()->table(self::TABLE, function (Blueprint $table) {
            $table->text('embedding')->nullable();
        });
    }
}
