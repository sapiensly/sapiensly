<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gated-write proposals for workflows (FR-5.3/9.3). When a real run reaches a
 * non-`safe` connector write, the engine records the parametrized action + a
 * human-readable effect preview here and stops (propose-don't-mutate). An
 * approver later executes the action via the shared write path.
 *
 * Tenant schema, Row-Level Security, scoped by organization_id/user_id like the
 * rest of the workflow runtime. Runs as the owner.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'workflow_proposals';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));
        $platform = $this->quoteIdentifier((string) config('tenancy.platform_role', 'platform_app'));
        $qualified = Schemas::qualify(self::TABLE);

        $predicate = <<<'SQL'
            CASE
                WHEN nullif(current_setting('app.organization_id', true), '') IS NULL
                    THEN organization_id IS NULL
                         AND user_id = nullif(current_setting('app.user_id', true), '')::bigint
                ELSE organization_id = nullif(current_setting('app.organization_id', true), '')
            END
        SQL;

        DB::statement("CREATE TABLE IF NOT EXISTS {$qualified} (
            id varchar(255) PRIMARY KEY,
            organization_id varchar(255),
            user_id bigint,
            app_id varchar(255) NOT NULL,
            workflow_id varchar(255) NOT NULL,
            run_id varchar(255) NOT NULL,
            step_id varchar(255) NOT NULL,
            effect varchar(40) NOT NULL DEFAULT 'write',
            action jsonb NOT NULL,
            preview text,
            status varchar(40) NOT NULL DEFAULT 'pending',
            resolved_by_user_id bigint,
            resolved_at timestamp(0) without time zone,
            created_at timestamp(0) without time zone,
            updated_at timestamp(0) without time zone
        )");

        DB::statement("CREATE INDEX IF NOT EXISTS workflow_proposals_run_idx ON {$qualified} (run_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS workflow_proposals_status_idx ON {$qualified} (status)");
        DB::statement("CREATE INDEX IF NOT EXISTS workflow_proposals_tenant_key_idx ON {$qualified} (organization_id, user_id)");

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$qualified} TO {$tenant}");
        DB::statement("REVOKE ALL ON {$qualified} FROM {$platform}");

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

        $qualified = Schemas::qualify(self::TABLE);

        DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
        DB::statement("DROP TABLE IF EXISTS {$qualified}");
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
