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

            // Tolerate tenant tables introduced AFTER this foundation migration
            // (created + keyed + protected by their own later migration, e.g.
            // move_runtime_agent_to_tenant): skip any that don't exist yet so a
            // fresh `migrate` doesn't fail on a not-yet-created table.
            if (DB::connection($this->connection)->scalar('select to_regclass(?)', [$qualified]) === null) {
                continue;
            }

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
