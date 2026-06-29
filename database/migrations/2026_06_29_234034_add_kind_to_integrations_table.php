<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // Discriminates HTTP / MCP / database connections. `is_mcp` is kept
            // and stays in sync with this via the model's saving hook.
            $table->string('kind')->default('http')->after('is_mcp');
        });

        // Backfill existing MCP connections (same session as the DDL above, so
        // the new column is already visible).
        DB::statement("UPDATE integrations SET kind = 'mcp' WHERE is_mcp = true");
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
