<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One queued export run — the mirror of `app_imports`, and for the same reason:
 * the work outlives the request that asked for it, so "is it ready" has to be
 * answerable later, from a reload or another device.
 *
 * It also holds WHERE the finished file landed and WHEN it stops being served.
 * An export is a copy of tenant data sitting outside the database; leaving it
 * there forever would quietly turn every download into a second, unmanaged
 * store of the same rows.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'app_exports';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable(self::TABLE)) {
            Schema::connection($this->connection)->create(self::TABLE, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('app_id');
                $table->string('object_id');
                $table->string('object_name')->nullable();
                $table->string('format')->default('csv');
                // The role the export ran as, so a finished file can never be
                // handed to someone the rows were never narrowed for.
                $table->string('role_slug')->nullable();
                $table->unsignedBigInteger('requested_by_user_id')->nullable();

                // queued | running | completed | failed
                $table->string('status')->default('queued');
                $table->unsignedInteger('rows_written')->default(0);

                $table->string('disk')->nullable();
                $table->string('storage_path')->nullable();
                $table->string('file_name')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();

                $table->text('error')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['app_id', 'created_at']);
            });
        }

        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

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
        if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
            $qualified = Schemas::qualify(self::TABLE);
            DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
        }

        Schema::connection($this->connection)->dropIfExists(Schemas::qualify(self::TABLE));
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
