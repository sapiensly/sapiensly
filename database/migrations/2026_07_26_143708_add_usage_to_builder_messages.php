<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Running AI-usage tally for an assistant builder turn, persisted
     * INCREMENTALLY as each model round-trip (StreamEnd) completes:
     * {model, prompt_tokens, completion_tokens, cache_read_input_tokens,
     * cache_write_input_tokens, reasoning_tokens, recorded}. Its whole reason
     * to exist is the timeout path: RunBuilderAiJob is hard-killed at its
     * wall-clock cap, so BuilderAiService's end-of-turn AiUsageRecorder::record
     * never runs and the turn's spend went UNATTRIBUTED (get_build_cost's
     * reconciliation flagged it). failed() now flushes this snapshot into an
     * ai_usage_event, so a timed-out turn still bills. `recorded` guards against
     * double-billing when the stream finished but a LATER step (commit/apply)
     * timed out.
     */
    public function up(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->jsonb('usage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn('usage');
        });
    }
};
