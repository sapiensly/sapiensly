<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            // Make knowledge_base_document_id nullable for new Document-based flow
            $table->string('knowledge_base_document_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base_chunks', function (Blueprint $table) {
            $table->string('knowledge_base_document_id', 36)->nullable(false)->change();
        });
    }
};
