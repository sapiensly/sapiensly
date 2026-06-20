<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MCP access is now organization-bound: a token authenticates a user but acts
 * within a specific organization (the per-org MCP URL), instead of following the
 * user's mutable active org. Backfills existing rows from the owning user's
 * current organization; new tokens always set it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_access_tokens', function (Blueprint $table) {
            $table->string('organization_id', 36)->nullable()->after('user_id');
            $table->index('organization_id');
        });

        DB::table('mcp_access_tokens')->update([
            'organization_id' => DB::raw('(select organization_id from users where users.id = mcp_access_tokens.user_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('mcp_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
