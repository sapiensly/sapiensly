<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Express pipeline runs: one row per L4 dashboard-express execution — the
 * persisted state machine (current phase, per-gate telemetry, outcome) that
 * makes a run observable, resumable and auditable. Tenant data: relocated to
 * the tenant schema + RLS by the companion move migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('app_id')->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('kind')->default('dashboard_express');
            $table->string('status')->default('running'); // running|succeeded|stopped|failed|halted_unanswerable
            $table->string('phase')->nullable();
            $table->text('prompt')->nullable();
            /** Per-gate telemetry: name → {model, latency_ms, tokens, fallback_used, output?}. */
            $table->jsonb('gates')->nullable();
            /** Free-form result payload: page slug/path, substitutions, notes. */
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
