<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables Row-Level Security on every tenant-schema table and installs the
 * tenant-isolation policy. The predicate mirrors HasVisibility::forAccountContext:
 *
 *   - business mode (app.organization_id set): row.organization_id must match.
 *   - personal mode (app.organization_id empty): row has no org AND
 *     row.user_id matches app.user_id.
 *
 * With neither GUC set, current_setting(..., true) yields '' so nothing matches
 * and the connection is fail-closed (zero rows). RLS does not apply to the table
 * owner (postgres), so migrations/seeders run unfiltered; tenant_app is filtered.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $predicate = <<<'SQL'
            CASE
                WHEN nullif(current_setting('app.organization_id', true), '') IS NULL
                    THEN organization_id IS NULL
                         AND user_id = nullif(current_setting('app.user_id', true), '')::bigint
                ELSE organization_id = nullif(current_setting('app.organization_id', true), '')
            END
        SQL;

        foreach (Schemas::tenantTables() as $table) {
            $qualified = Schemas::qualify($table);

            DB::statement("ALTER TABLE {$qualified} ENABLE ROW LEVEL SECURITY");
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
            DB::statement(
                'CREATE POLICY '.self::POLICY." ON {$qualified} ".
                "USING ({$predicate}) WITH CHECK ({$predicate})"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (Schemas::tenantTables() as $table) {
            $qualified = Schemas::qualify($table);
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
            DB::statement("ALTER TABLE {$qualified} DISABLE ROW LEVEL SECURITY");
        }
    }
};
