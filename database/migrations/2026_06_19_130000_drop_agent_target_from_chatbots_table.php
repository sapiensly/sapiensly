<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The destructive cut: an AI Bot no longer points at an Agent or AgentTeam —
     * its agents live in its Bot Flow. Drop the legacy target columns.
     */
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['agent_team_id']);
            $table->dropColumn(['agent_id', 'agent_team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('agent_id', 36)->nullable();
            $table->string('agent_team_id', 36)->nullable();
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            $table->foreign('agent_team_id')->references('id')->on('agent_teams')->nullOnDelete();
        });
    }
};
