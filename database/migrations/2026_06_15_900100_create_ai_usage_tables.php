<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org-level AI spend tracking (phase 1). Two tenant tables:
 *   - ai_usage_events: one append-only row per AI call (tokens + computed cost,
 *     own-vs-system source, module). High write.
 *   - ai_usage_daily: per-org/day/model/source rollup the dashboards read.
 *
 * Created in `platform` here (unqualified names resolve there on the owner's
 * search_path); the companion migration relocates them to `tenant` + RLS +
 * fill_tenant_key trigger. organization_id / user_id are the tenant key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('organization_id', 36)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module', 40);          // chat | builder | runtime_agent | agent | debate | widget | workflow | embeddings
            $table->string('driver', 40);          // anthropic | openai | ...
            $table->string('model');
            $table->string('source', 10);          // own | system
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('cost', 14, 6)->default(0);
            $table->boolean('estimated')->default(false);
            $table->string('status', 20)->default('success'); // success | error
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'source']);
        });

        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('organization_id', 36)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('date');
            $table->string('module', 40);
            $table->string('driver', 40);
            $table->string('model');
            $table->string('source', 10);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('reasoning_tokens')->default(0);
            $table->decimal('cost', 16, 6)->default(0);
            $table->timestamps();

            // One row per org/day/model/source/module — the aggregator upserts on it.
            $table->unique(['organization_id', 'date', 'module', 'driver', 'model', 'source'], 'ai_usage_daily_key');
            $table->index(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_usage_daily');
    }
};
