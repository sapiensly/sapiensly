<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->string('document_id', 36);
            $table->string('knowledge_base_id', 36);
            $table->string('embedding_status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->cascadeOnDelete();

            $table->foreign('knowledge_base_id')
                ->references('id')
                ->on('knowledge_bases')
                ->cascadeOnDelete();

            $table->unique(['document_id', 'knowledge_base_id']);
            $table->index(['knowledge_base_id', 'embedding_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_knowledge_base');
    }
};
