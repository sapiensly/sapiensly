<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant the tenant role USAGE on the AI usage tables' identity sequences.
 *
 * {@see 2026_06_15_900101_move_ai_usage_to_tenant} relocated ai_usage_events /
 * ai_usage_daily from `platform` to `tenant` and granted table privileges, but
 * the owned id sequences moved with the tables WITHOUT a sequence grant — and
 * the schema-wide sequence grant in {@see 2026_06_04_900000_relocate_tenant_tables}
 * had already run before these tables existed. The result: every tenant_app
 * INSERT failed with "permission denied for sequence ...", which the usage
 * recorder swallows, so no usage row was ever written (both spend dashboards
 * read empty). Idempotent; runs as the owner so it can grant on the sequences.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const SEQUENCES = [
        'tenant.ai_usage_events_id_seq',
        'tenant.ai_usage_daily_id_seq',
    ];

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));

        foreach (self::SEQUENCES as $sequence) {
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequence} TO {$tenant}");
        }
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));

        foreach (self::SEQUENCES as $sequence) {
            DB::statement("REVOKE ALL ON SEQUENCE {$sequence} FROM {$tenant}");
        }
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
