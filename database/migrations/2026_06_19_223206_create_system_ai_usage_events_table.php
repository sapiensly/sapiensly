<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's system-AI cost ledger: one append-only row per AI call billed
 * to a PLATFORM/system provider key (the platform pays). Lives in `platform`
 * (control-plane, no RLS) — deliberately NOT a tenant table, so a system call
 * that has no resolvable tenant context (e.g. a background/system job, or an
 * embedding with no owner) is still recorded. organization_id / user_id are kept
 * for attribution when known, but are nullable and carry no RLS scoping.
 *
 * This complements tenant.ai_usage_events: that table is the per-org meter (what
 * each tenant consumed, RLS-scoped, own + system); this table is the platform
 * meter (total system liability across every org, including the unattributable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_ai_usage_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('organization_id', 36)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module', 40);          // chat | builder | runtime_agent | agent | debate | widget | workflow | embeddings
            $table->string('driver', 40);          // anthropic | openai | ...
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('cost', 14, 6)->default(0);
            $table->boolean('estimated')->default(false);
            $table->string('status', 20)->default('success'); // success | error
            $table->timestamps();

            $table->index('created_at');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_ai_usage_events');
    }
};
