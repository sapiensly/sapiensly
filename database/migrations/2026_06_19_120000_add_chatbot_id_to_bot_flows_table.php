<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1a of the Bot Flows restructure: a Bot Flow is owned by a Chatbot
     * (UI: "AI Bot") one-to-one. Additive only — the legacy agent_id link is
     * dropped in the later destructive cut once the runtime is repointed.
     */
    public function up(): void
    {
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->string('chatbot_id', 36)->nullable()->after('agent_id');

            $table->foreign('chatbot_id')
                ->references('id')
                ->on('chatbots')
                ->nullOnDelete();

            // At most one Bot Flow per Chatbot. Postgres allows multiple NULLs,
            // so unattached draft flows are still permitted.
            $table->unique('chatbot_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->dropForeign(['chatbot_id']);
            $table->dropUnique(['chatbot_id']);
            $table->dropColumn('chatbot_id');
        });
    }
};
