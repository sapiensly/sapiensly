<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_base_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('knowledge_base_document_id', 36);
            $table->string('knowledge_base_id', 36);
            $table->text('content');
            $table->unsignedInteger('chunk_index');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('knowledge_base_document_id')
                ->references('id')
                ->on('knowledge_base_documents')
                ->cascadeOnDelete();

            $table->foreign('knowledge_base_id')
                ->references('id')
                ->on('knowledge_bases')
                ->cascadeOnDelete();

            $table->index(['knowledge_base_id', 'chunk_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_chunks');
    }
};
