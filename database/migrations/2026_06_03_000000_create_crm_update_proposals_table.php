<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Proposals produced by capability #0001 (the HubSpot post-call agent): a drafted
 * CRM update awaiting human approval, plus the outcome once applied. This is
 * tenant runtime data — the "Box C" of the capability model — so it carries the
 * denormalized RLS key and the tenant_isolation policy, exactly like the chat
 * action-proposal rows it mirrors.
 *
 * Dated before the 2026_06_04_9000xx foundation migrations so the table exists
 * when they iterate {@see Schemas::tenantTables()} on a fresh DB; the setup here
 * is fully idempotent (create / relocate / key / RLS / trigger) so it also
 * performs the complete setup standalone on an already-migrated DB. Runs as owner.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'crm_update_proposals';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS crm_update_proposals (
                id varchar(36) PRIMARY KEY,
                capability_id varchar(64) NOT NULL,
                call_id varchar(255) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                target jsonb,
                operation varchar(20),
                changes jsonb,
                rationale text,
                confidence double precision,
                evidence jsonb,
                call_snapshot jsonb,
                source_fetched_at timestamptz,
                approver_id bigint,
                applied_at timestamptz,
                external_object_id varchar(255),
                error text,
                organization_id varchar(255),
                user_id bigint,
                created_at timestamptz NULL,
                updated_at timestamptz NULL
            )
        SQL);

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

        DB::statement('CREATE INDEX IF NOT EXISTS '.self::TABLE.'_tenant_key_idx ON '.$qualified.' (organization_id, user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS '.self::TABLE.'_call_id_idx ON '.$qualified.' (call_id)');

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
