<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roster of agents participating in a multi-agent (@mention) chat thread.
 *
 * A tenant-data table from birth: it carries the denormalized RLS key
 * (organization_id / user_id) and the tenant_isolation policy, just like the
 * sibling chat_* tables. The agent_id references platform.agents but is kept as
 * a plain column (no cross-schema FK) — mirroring chats.agent_id.
 *
 * Dated before the 2026_06_04_9000xx foundation migrations so that, on a fresh
 * database, the table already exists when those migrations iterate
 * {@see Schemas::tenantTables()} (which now lists `chat_agents`). The setup here
 * is fully idempotent — create / relocate / key / RLS / trigger — so on an
 * already-migrated database (where the foundation migrations ran without this
 * table) running it standalone performs the complete setup. Runs as the owner.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'chat_agents';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        // 1. Create the table. Unqualified, so it lands in `platform` (the owner's
        //    search_path), exactly like the other chat_* create migrations; it is
        //    relocated to `tenant` below.
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chat_agents (
                id varchar(36) PRIMARY KEY,
                chat_id varchar(36) NOT NULL,
                agent_id varchar(36) NOT NULL,
                organization_id varchar(255),
                user_id bigint,
                joined_at timestamptz NOT NULL DEFAULT now(),
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                UNIQUE (chat_id, agent_id)
            )
        SQL);

        // 2. Relocate to the tenant schema (idempotent — only if still in platform).
        $schema = DB::connection($this->connection)->scalar(
            "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
            [self::TABLE]
        );

        if ($schema === Schemas::PLATFORM) {
            DB::statement('ALTER TABLE platform.'.self::TABLE.' SET SCHEMA tenant');
        }

        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));
        $platform = $this->quoteIdentifier((string) config('tenancy.platform_role', 'platform_app'));
        $qualified = Schemas::qualify(self::TABLE);

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$qualified} TO {$tenant}");
        DB::statement("REVOKE ALL ON {$qualified} FROM {$platform}");

        // 3. Tenant key index (the columns are declared above).
        DB::statement('CREATE INDEX IF NOT EXISTS '.self::TABLE.'_tenant_key_idx ON '.$qualified.' (organization_id, user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS '.self::TABLE.'_chat_id_idx ON '.$qualified.' (chat_id)');

        // 4. Row-Level Security + the shared tenant_isolation policy.
        $predicate = <<<'SQL'
            CASE
                WHEN nullif(current_setting('app.organization_id', true), '') IS NULL
                    THEN organization_id IS NULL
                         AND user_id = nullif(current_setting('app.user_id', true), '')::bigint
                ELSE organization_id = nullif(current_setting('app.organization_id', true), '')
            END
        SQL;

        DB::statement("ALTER TABLE {$qualified} ENABLE ROW LEVEL SECURITY");
        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
        DB::statement(
            'CREATE POLICY '.self::POLICY." ON {$qualified} ".
            "USING ({$predicate}) WITH CHECK ({$predicate})"
        );

        // 5. Auto-fill trigger (the tenant.fill_tenant_key() function is created by
        //    the foundation migration; on a fresh DB this trigger is replaced
        //    idempotently when that migration later runs).
        DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
        DB::statement("CREATE TRIGGER fill_tenant_key BEFORE INSERT ON {$qualified} FOR EACH ROW EXECUTE FUNCTION tenant.fill_tenant_key()");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = DB::connection($this->connection)->scalar(
            "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
            [self::TABLE]
        );

        if ($schema !== null) {
            DB::statement("DROP TABLE IF EXISTS {$schema}.".self::TABLE.' CASCADE');
        }
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
