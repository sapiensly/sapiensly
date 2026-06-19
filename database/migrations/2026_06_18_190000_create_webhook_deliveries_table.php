<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inbound webhook delivery ledger for `webhook.inbound`-triggered workflows
 * (FR-4.3). Lives in the `tenant` schema under Row-Level Security, scoped by
 * organization_id/user_id like every other workflow-runtime table. A unique
 * (workflow_id, delivery_key) row is the dedupe gate: providers retry the same
 * delivery, and a duplicate insert must not fire the workflow twice.
 *
 * Created directly in `tenant` (not relocated) and given the same grants/policy/
 * trigger the relocate migrations apply. Runs as the owner.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'webhook_deliveries';

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
            delivery_key varchar(255) NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'accepted',
            created_at timestamp(0) without time zone,
            updated_at timestamp(0) without time zone
        )");

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS webhook_deliveries_dedupe_idx ON {$qualified} (workflow_id, delivery_key)");
        DB::statement("CREATE INDEX IF NOT EXISTS webhook_deliveries_tenant_key_idx ON {$qualified} (organization_id, user_id)");

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
