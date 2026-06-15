<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auto-fills a tenant row's RLS key (organization_id / user_id) from the current
 * session GUCs on INSERT when the application did not set them explicitly.
 *
 * Without this, every tenant-row insert would have to set organization_id (and
 * user_id in personal mode) by hand or the RLS WITH CHECK clause would reject it
 * — hundreds of call sites, most of which create child rows that only carry a
 * parent FK. A BEFORE INSERT trigger runs before WITH CHECK is evaluated, so the
 * filled-in values satisfy the policy. Explicitly-set values are left untouched.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tenant.fill_tenant_key() RETURNS trigger AS $$
            BEGIN
                IF NEW.organization_id IS NULL THEN
                    NEW.organization_id := nullif(current_setting('app.organization_id', true), '');
                END IF;
                IF NEW.user_id IS NULL THEN
                    NEW.user_id := nullif(current_setting('app.user_id', true), '')::bigint;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (Schemas::tenantTables() as $table) {
            $qualified = Schemas::qualify($table);

            // Skip tenant tables introduced after this foundation migration —
            // their own later migration installs the trigger for them.
            if (DB::connection($this->connection)->scalar('select to_regclass(?)', [$qualified]) === null) {
                continue;
            }

            DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
            DB::statement("CREATE TRIGGER fill_tenant_key BEFORE INSERT ON {$qualified} FOR EACH ROW EXECUTE FUNCTION tenant.fill_tenant_key()");
        }
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (Schemas::tenantTables() as $table) {
            DB::statement('DROP TRIGGER IF EXISTS fill_tenant_key ON '.Schemas::qualify($table));
        }

        DB::statement('DROP FUNCTION IF EXISTS tenant.fill_tenant_key()');
    }
};
