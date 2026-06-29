<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            // The persistent, cross-turn build plan for this conversation: an
            // ordered list of steps the builder works through over several turns.
            // Source of truth for step status; progress is closed deterministically
            // when a turn's propose_change actually applies (see BuildPlan). Null
            // until the agent calls set_build_plan. Shared by the in-app chat and
            // the MCP continue_builder_conversation path (same BuilderAiService).
            $table->jsonb('build_plan')->nullable()->after('status');
        });

        Schema::table('builder_messages', function (Blueprint $table) {
            // The build-plan step ids this turn targeted (closed, failed, or
            // reset). Audit + UI ("✓ completó: …"); null when the turn touched
            // no plan step.
            $table->jsonb('plan_step_ids')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            $table->dropColumn('build_plan');
        });

        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn('plan_step_ids');
        });
    }
};
