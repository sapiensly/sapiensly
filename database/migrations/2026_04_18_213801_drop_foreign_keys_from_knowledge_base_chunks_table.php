<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the foreign-key constraints on knowledge_base_chunks. With the vector
 * store able to live on a tenant-scoped PostgreSQL database (decoupled from
 * the application DB), FKs referencing knowledge_bases, knowledge_base_documents,
 * and documents can no longer be enforced — the referenced rows may be on a
 * different physical database. Columns and indexes are preserved so lookups
 * by id remain fast.
 *
 * Cascading deletes that used to rely on these FKs are now performed
 * explicitly via VectorStoreService + KnowledgeScopeWiper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            $table->dropForeign(['knowledge_base_document_id']);
            $table->dropForeign(['knowledge_base_id']);
            $table->dropForeign(['document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            $table->foreign('knowledge_base_document_id')
                ->references('id')
                ->on('knowledge_base_documents')
                ->cascadeOnDelete();

            $table->foreign('knowledge_base_id')
                ->references('id')
                ->on('knowledge_bases')
                ->cascadeOnDelete();

            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->nullOnDelete();
        });
    }
};
