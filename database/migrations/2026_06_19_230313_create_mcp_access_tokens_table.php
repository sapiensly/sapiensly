<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens that let an external MCP client (Claude Code / Claude web)
 * authenticate as a Sapiensly user. Platform/control-plane data — the token
 * resolves to a user, and the user's organization_id drives tenant scope (RLS),
 * mirroring how ChatbotApiToken backs the widget API. `abilities` gates which
 * MCP tool groups the token may use (apps:build, data:read, data:write,
 * agents:invoke); null/empty means all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_access_tokens', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_access_tokens');
    }
};
