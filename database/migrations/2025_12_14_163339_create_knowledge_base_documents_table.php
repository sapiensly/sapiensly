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
        Schema::create('knowledge_base_documents', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // doc_01JFXYZ...
            $table->string('knowledge_base_id', 36);
            $table->string('type');
            $table->string('source');
            $table->string('original_filename')->nullable();
            $table->longText('content')->nullable();
            $table->json('metadata')->nullable();
            $table->string('embedding_status')->default('pending');
            $table->text('error_message')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->foreign('knowledge_base_id')
                ->references('id')
                ->on('knowledge_bases')
                ->cascadeOnDelete();

            $table->index(['knowledge_base_id', 'type']);
            $table->index(['knowledge_base_id', 'embedding_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_documents');
    }
};
