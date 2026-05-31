<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_project_knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('chat_project_id', 36);
            $table->string('knowledge_base_id', 36);
            $table->timestamps();

            $table->foreign('chat_project_id')->references('id')->on('chat_projects')->cascadeOnDelete();
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases')->cascadeOnDelete();
            $table->unique(['chat_project_id', 'knowledge_base_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_project_knowledge_bases');
    }
};
