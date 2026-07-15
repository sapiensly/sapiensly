<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete an app should take its pipeline_runs and app_user_roles with it. Every
 * other tenant table that carries an app_id (records, builder_conversations,
 * app_files, workflow_runs, runtime_agent_conversations) has an ON DELETE
 * CASCADE FK to platform.apps, so `$app->delete()` — the MCP delete_app tool and
 * the controller both call it — cleans up. These two tables were missed, so
 * deleting an app orphaned its runs and role assignments.
 *
 * Backfill the missing cascade: purge existing orphans (app_id is NOT NULL, so
 * they can't be nulled), then add the same FK the other tables carry.
 */
return new class extends Migration
{
    /** @var array<string, string> tenant table => app_id column */
    private array $tables = [
        'pipeline_runs' => 'app_id',
        'app_user_roles' => 'app_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $col) {
            $qualified = "tenant.{$table}";

            // Orphans would block the FK — remove rows whose app is already gone.
            DB::statement("DELETE FROM {$qualified} t WHERE t.{$col} IS NOT NULL AND NOT EXISTS (SELECT 1 FROM platform.apps a WHERE a.id = t.{$col})");

            $constraint = "{$table}_{$col}_apps_cascade";
            $exists = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conname = ?',
                [$constraint],
            );
            if ($exists === null) {
                DB::statement("ALTER TABLE {$qualified} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$col}) REFERENCES platform.apps(id) ON DELETE CASCADE");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $col) {
            DB::statement("ALTER TABLE tenant.{$table} DROP CONSTRAINT IF EXISTS {$table}_{$col}_apps_cascade");
        }
    }
};
