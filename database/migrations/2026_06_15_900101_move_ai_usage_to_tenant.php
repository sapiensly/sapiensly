<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promote the AI usage tables (org-level spend tracking) from `platform` to the
 * `tenant` schema and put them under Row-Level Security — they hold per-tenant
 * usage/cost data, scoped by organization_id. Mirrors
 * {@see 2026_06_14_900001_move_runtime_agent_to_tenant}. Idempotent; runs as the
 * owner so it can ALTER schema + ownership.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLES = ['ai_usage_events', 'ai_usage_daily'];

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));
        $platform = $this->quoteIdentifier((string) config('tenancy.platform_role', 'platform_app'));

        $predicate = <<<'SQL'
            CASE
                WHEN nullif(current_setting('app.organization_id', true), '') IS NULL
                    THEN organization_id IS NULL
                         AND user_id = nullif(current_setting('app.user_id', true), '')::bigint
                ELSE organization_id = nullif(current_setting('app.organization_id', true), '')
            END
        SQL;

        foreach (self::TABLES as $table) {
            $schema = DB::connection($this->connection)->scalar(
                "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
                [$table]
            );
            if ($schema === Schemas::PLATFORM) {
                DB::statement("ALTER TABLE platform.{$table} SET SCHEMA tenant");
            }

            $qualified = Schemas::qualify($table);

            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$qualified} TO {$tenant}");
            DB::statement("REVOKE ALL ON {$qualified} FROM {$platform}");

            // The owned id sequence moves with the table on SET SCHEMA but keeps
            // its old grants; without this the tenant role can't INSERT.
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE tenant.{$table}_id_seq TO {$tenant}");
            DB::statement("REVOKE ALL ON SEQUENCE tenant.{$table}_id_seq FROM {$platform}");

            DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS organization_id varchar(255)");
            DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS user_id bigint");
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_tenant_key_idx ON {$qualified} (organization_id, user_id)");

            DB::statement("ALTER TABLE {$qualified} ENABLE ROW LEVEL SECURITY");
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
            DB::statement(
                'CREATE POLICY '.self::POLICY." ON {$qualified} ".
                "USING ({$predicate}) WITH CHECK ({$predicate})"
            );

            DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
            DB::statement("CREATE TRIGGER fill_tenant_key BEFORE INSERT ON {$qualified} FOR EACH ROW EXECUTE FUNCTION tenant.fill_tenant_key()");
        }
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $qualified = Schemas::qualify($table);

            DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
            DB::statement("ALTER TABLE {$qualified} DISABLE ROW LEVEL SECURITY");

            $schema = DB::connection($this->connection)->scalar(
                "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
                [$table]
            );
            if ($schema === Schemas::TENANT) {
                DB::statement("ALTER TABLE tenant.{$table} SET SCHEMA platform");
            }
        }
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
