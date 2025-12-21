<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('visibility')->default('private');

            // Target: either an Agent OR an AgentTeam
            $table->string('agent_id', 36)->nullable();
            $table->string('agent_team_id', 36)->nullable();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');

            // Configuration (appearance, behavior, advanced)
            $table->json('config')->nullable();
            $table->json('allowed_origins')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            $table->foreign('agent_team_id')->references('id')->on('agent_teams')->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
