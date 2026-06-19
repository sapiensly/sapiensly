<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dissolve AgentTeam: agents are organised as nodes inside a Bot Flow now,
     * not as a separate team. Drops the team table and every reference to it.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['agent_team_id']);
            $table->dropColumn('agent_team_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });

        // channels.agent_id / agent_team_id were intentionally unconstrained.
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['agent_id', 'agent_team_id']);
        });

        Schema::dropIfExists('agent_teams');
    }

    public function down(): void
    {
        // One-way: the team model and its data are gone.
    }
};
