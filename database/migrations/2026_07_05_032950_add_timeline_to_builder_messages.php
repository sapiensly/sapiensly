<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-turn tool timeline for an assistant builder message: a list of
     * {tool, model_seconds, tool_seconds?, t, successful?} entries persisted
     * INCREMENTALLY while the turn runs, so even a hard-killed or timed-out
     * turn keeps the timing evidence up to the moment it died. Read through
     * the MCP (get_builder_conversation) to see where a slow build spent
     * its wall-clock without grepping worker logs.
     */
    public function up(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->jsonb('timeline')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn('timeline');
        });
    }
};
