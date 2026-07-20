<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playground run history: one row per capability test executed from the
 * Playground — the prompt/input, the resolved model, the (sanitized) response,
 * timing, token usage and the raw provider payload when available. Turns the
 * Playground from an ephemeral tester into a test suite / benchmark source.
 * Tenant data: relocated to the tenant schema + RLS by the companion move
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playground_runs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('capability')->index();
            // Nullable: a failed run may error before a model is resolved.
            $table->string('driver')->nullable();
            $table->string('model')->nullable();
            $table->string('status'); // ok|error
            /** The validated request input minus nulls (prompt/text/query/...). */
            $table->jsonb('input')->nullable();
            /** Uploaded file metadata: {name, mime, size} — the file itself is not kept. */
            $table->jsonb('file_meta')->nullable();
            /** Text output for text-producing capabilities. */
            $table->text('output_text')->nullable();
            /** Structured non-text output (embeddings preview, rerank scores, binary meta). */
            $table->jsonb('output')->nullable();
            /** The full (sanitized) JSON payload returned to the client. */
            $table->jsonb('response')->nullable();
            /** Raw provider response when the transport exposes it (OpenRouter). */
            $table->jsonb('raw')->nullable();
            /** Token usage + derived cost: {prompt_tokens, completion_tokens, total_tokens, cost, estimated}. */
            $table->jsonb('usage')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_runs');
    }
};
