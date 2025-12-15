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
        Schema::create('agent_knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 36);
            $table->string('knowledge_base_id', 36);
            $table->timestamps();

            $table->foreign('agent_id')
                ->references('id')
                ->on('agents')
                ->cascadeOnDelete();

            $table->foreign('knowledge_base_id')
                ->references('id')
                ->on('knowledge_bases')
                ->cascadeOnDelete();

            $table->unique(['agent_id', 'knowledge_base_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_knowledge_bases');
    }
};
