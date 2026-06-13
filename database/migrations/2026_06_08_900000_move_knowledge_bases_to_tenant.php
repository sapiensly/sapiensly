<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promotes `knowledge_bases` from a platform definition table to a tenant
 * data table.
 *
 * A knowledge base is per-tenant content: its children (`knowledge_base_chunks`,
 * `knowledge_base_documents`, the `document_knowledge_base` /
 * `chat_project_knowledge_bases` pivots) already live in the RLS-protected
 * `tenant` schema, but the parent row sat in `platform` without RLS. That split
 * made every tenant-model → KB relationship a cross-schema join that no runtime
 * role could resolve (e.g. Document::knowledgeBases()). Moving the table into
 * `tenant` makes those relationships same-schema and brings the KB row under the
 * same Row-Level Security as its content.
 *
 * Mirrors the `2026_06_04_9000xx_*` tenant-table migrations, scoped to this one
 * table. Idempotent: on a fresh database those migrations already relocate /
 * key / protect `knowledge_bases` (it is now in {@see Schemas::tenantTables()}),
 * so each step here no-ops; on an already-migrated database this performs the
 * move. Runs as the owner so it can ALTER the table's schema and ownership.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'knowledge_bases';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        // 1. Relocate the table (and its data, constraints, indexes) to tenant.
        $schema = DB::connection($this->connection)->scalar(
            "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
            [self::TABLE]
        );

        if ($schema === Schemas::PLATFORM) {
            DB::statement('ALTER TABLE platform.'.self::TABLE.' SET SCHEMA tenant');
        }

        // SET SCHEMA preserves the table's ACL, so fix the grants: tenant_app
        // gains DML, platform_app is locked out (it also lacks USAGE on tenant).
        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));
        $platform = $this->quoteIdentifier((string) config('tenancy.platform_role', 'platform_app'));
        $qualified = Schemas::qualify(self::TABLE);

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$qualified} TO {$tenant}");
        DB::statement("REVOKE ALL ON {$qualified} FROM {$platform}");

        // 2. Tenant key + index. organization_id / user_id already exist (added
        //    by the visibility + original migrations), so only the index is new.
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS organization_id varchar(255)");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS user_id bigint");
        DB::statement('CREATE INDEX IF NOT EXISTS '.self::TABLE.'_tenant_key_idx ON '.$qualified.' (organization_id, user_id)');

        // 3. Row-Level Security + the shared tenant_isolation policy.
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

        // 4. Auto-fill trigger (the tenant.fill_tenant_key() function already
        //    exists from the foundation migration).
        DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
        DB::statement("CREATE TRIGGER fill_tenant_key BEFORE INSERT ON {$qualified} FOR EACH ROW EXECUTE FUNCTION tenant.fill_tenant_key()");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify(self::TABLE);

        DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
        DB::statement("ALTER TABLE {$qualified} DISABLE ROW LEVEL SECURITY");

        $schema = DB::connection($this->connection)->scalar(
            "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
            [self::TABLE]
        );

        if ($schema === Schemas::TENANT) {
            DB::statement('ALTER TABLE tenant.'.self::TABLE.' SET SCHEMA platform');
        }
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
