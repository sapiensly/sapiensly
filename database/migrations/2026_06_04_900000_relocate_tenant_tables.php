<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relocates tenant-data tables from `platform` (where the original migrations
 * create them, since unqualified names resolve to the first schema on the
 * owner's search_path) into the `tenant` schema.
 *
 * `ALTER TABLE ... SET SCHEMA` preserves every constraint, index and FK name —
 * unlike qualifying the original `Schema::create('tenant.x')` call, which would
 * rewrite auto-generated index names to a `tenant_`-prefixed form and break the
 * later migrations that drop those constraints by their original name.
 *
 * Idempotent: only moves a table that is still sitting in `platform`.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (Schemas::tenantTables() as $table) {
            $schema = DB::connection($this->connection)->scalar(
                "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
                [$table]
            );

            if ($schema === Schemas::PLATFORM) {
                DB::statement("ALTER TABLE platform.{$table} SET SCHEMA tenant");
            }
        }

        // SET SCHEMA preserves a table's ACL, so the relocated tables still carry
        // the platform_app grants they got (via default privileges) when first
        // created in `platform`, and lack tenant_app grants. Normalize ownership
        // of the tenant schema: tenant_app holds DML, platform_app is locked out
        // (it also has no USAGE on `tenant`, so this is belt-and-braces).
        $tenant = $this->quoteIdentifier((string) config('database.connections.tenant.username', 'tenant_app'));
        $platform = $this->quoteIdentifier((string) config('database.connections.platform.username', 'platform_app'));

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA tenant TO {$tenant}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA tenant TO {$tenant}");
        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA tenant FROM {$platform}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA tenant FROM {$platform}");
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (Schemas::tenantTables() as $table) {
            $schema = DB::connection($this->connection)->scalar(
                "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
                [$table]
            );

            if ($schema === Schemas::TENANT) {
                DB::statement("ALTER TABLE tenant.{$table} SET SCHEMA platform");
            }
        }
    }
};
