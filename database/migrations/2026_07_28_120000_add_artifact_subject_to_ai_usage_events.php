<?php

use App\Services\Ai\AiUsageReport;
use App\Support\Ai\SpendArtifact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name the artifact an AI call was spent on.
 *
 * `app_id` already answers this for the three app-shaped modules (builder,
 * express, landing_director), but most spend does not belong to an App: a chat,
 * a debate, a slide deck, a knowledge base being embedded, a chatbot serving a
 * visitor. Reusing `app_id` for those would corrupt the per-build reads that
 * {@see AiUsageReport::forApp()} and `get_build_cost` do, so the
 * subject is polymorphic instead.
 *
 * `subject_type` holds a short slug ('chat', 'chatbot', 'debate', …) rather than
 * a class name: the ledger is append-only and read by humans and by SQL, and a
 * slug survives a class being moved or renamed. {@see SpendArtifact}
 * is the one place the slug maps back to a model.
 *
 * Both ledgers get the columns — a system-paid call must be attributable from
 * the control plane too. Nullable, and backfilled for nothing: rows written
 * before this shipped keep their `app_id` (which still resolves to an App) and
 * everything else reads as unattributed, which is the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_usage_events', 'system_ai_usage_events'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('subject_type', 32)->nullable();
                $table->string('subject_id', 40)->nullable();
                // Named explicitly: the generated name would run past Postgres'
                // 63-character identifier limit and be silently truncated.
                $table->index(['organization_id', 'subject_type', 'subject_id'], $name.'_subject_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_usage_events', 'system_ai_usage_events'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_subject_idx');
                $table->dropColumn(['subject_type', 'subject_id']);
            });
        }
    }
};
