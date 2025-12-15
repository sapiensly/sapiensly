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
        Schema::create('agents', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // agent_01JFXYZ...
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('agent_team_id', 36)->nullable();
            $table->string('type');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->text('prompt_template')->nullable();
            $table->string('model')->default('claude-sonnet-4-20250514');
            $table->json('config')->nullable();
            $table->timestamps();

            $table->foreign('agent_team_id')
                ->references('id')
                ->on('agent_teams')
                ->cascadeOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['agent_team_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
