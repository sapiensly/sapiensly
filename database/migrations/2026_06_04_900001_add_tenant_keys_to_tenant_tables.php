<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensures every tenant-schema table carries a uniform RLS key: `organization_id`
 * (business mode) and `user_id` (personal mode / owner). tenant_app cannot read
 * platform parents, so the scope must live on the row itself.
 *
 * Idempotent (ADD COLUMN IF NOT EXISTS): tables that already declared
 * `organization_id` keep theirs untouched; the rest gain the columns. Runtime
 * write paths populate these (see the tenant-context wiring).
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
            $qualified = Schemas::qualify($table);
            $index = $table.'_tenant_key_idx';

            DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS organization_id varchar(255)");
            DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS user_id bigint");
            DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON {$qualified} (organization_id, user_id)");
        }
    }

    public function down(): void
    {
        // No-op: the columns may pre-date this migration on some tables and the
        // greenfield flow rebuilds from scratch, so we do not drop them here.
    }
};
