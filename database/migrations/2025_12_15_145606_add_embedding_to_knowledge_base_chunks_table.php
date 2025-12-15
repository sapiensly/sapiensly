<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            $table->string('embedding_model')->nullable()->after('metadata');
        });

        // Add vector column - pgvector for PostgreSQL, text for SQLite (testing)
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Using 1536 dimensions for text-embedding-3-small (default)
            // Note: HNSW index has max 2000 dimensions limit
            DB::statement('ALTER TABLE knowledge_base_chunks ADD COLUMN embedding vector(1536)');

            // Add HNSW index for fast similarity search
            DB::statement('CREATE INDEX knowledge_base_chunks_embedding_idx ON knowledge_base_chunks USING hnsw (embedding vector_cosine_ops)');
        } else {
            // For SQLite (testing), use a text column as placeholder
            Schema::table('knowledge_base_chunks', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS knowledge_base_chunks_embedding_idx');
            DB::statement('ALTER TABLE knowledge_base_chunks DROP COLUMN IF EXISTS embedding');
        } else {
            Schema::table('knowledge_base_chunks', function (Blueprint $table) {
                $table->dropColumn('embedding');
            });
        }

        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            $table->dropColumn('embedding_model');
        });
    }
};
