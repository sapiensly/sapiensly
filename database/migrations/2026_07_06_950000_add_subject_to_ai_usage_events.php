<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute an AI call to the app + builder conversation it served, so cost can
 * be read PER BUILD instead of only per module/model. Nullable: most calls
 * (chat, embeddings, standalone agents) have no app subject and stay unset; the
 * builder and Express dashboard pipeline fill them.
 *
 * Both ledgers get the columns: the tenant meter (ai_usage_events, RLS) and the
 * platform meter (system_ai_usage_events) — a system-paid build must be
 * attributable from the control plane too. The daily rollup is intentionally
 * left at its module/model grain (per-app rollups would explode its cardinality);
 * the per-event tag is what enables ad-hoc per-build cost queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_usage_events', 'system_ai_usage_events'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('app_id', 40)->nullable();
                $table->string('conversation_id', 40)->nullable();
                $table->index(['organization_id', 'app_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_usage_events', 'system_ai_usage_events'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'app_id']);
                $table->dropColumn(['app_id', 'conversation_id']);
            });
        }
    }
};
