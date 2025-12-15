<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            // Add new document_id column (nullable for migration period)
            $table->string('document_id', 36)->nullable()->after('knowledge_base_document_id');

            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->nullOnDelete();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropIndex(['document_id']);
            $table->dropColumn('document_id');
        });
    }
};
