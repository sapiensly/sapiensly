<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every platform-administration write, recorded. Control-plane data (no tenant
 * key, no RLS): the whole point of the log is that it spans organizations, so
 * scoping it to one would defeat it.
 *
 * The actor is stored denormalized (id + email) rather than only as a foreign
 * key, because deleting a user is itself an auditable action and the row must
 * survive its subject. `target_type`/`target_id` name what was acted on, `meta`
 * carries the sanitized arguments — never a secret; the tools that touch
 * credentials record that a key was rotated, not the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_log', function (Blueprint $table) {
            $table->string('id', 40)->primary();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('organization_id')->nullable();

            // The MCP tool (or web action) that performed the write.
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('target_label')->nullable();

            // 'ok' | 'refused' | 'failed'
            $table->string('result')->default('ok');
            $table->text('summary')->nullable();
            $table->json('meta')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('channel', 20)->default('mcp');

            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_log');
    }
};
