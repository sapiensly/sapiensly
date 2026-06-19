<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The final cut: a Bot Flow belongs to a surface (chatbot or WhatsApp
     * connection), never to an agent. Drop the legacy per-agent flow link.
     */
    public function up(): void
    {
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropIndex(['agent_id']);
            $table->dropColumn('agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->string('agent_id', 36)->nullable();
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->index('agent_id');
        });
    }
};
