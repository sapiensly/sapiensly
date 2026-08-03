<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What has happened to a record: the changes it went through, and what people
 * said about it.
 *
 * ONE table for both, because they answer one question. Somebody opening an
 * order asks "why is this still waiting?" and the answer is half machine
 * ("status: recibida → esperando refacción, by Ana, Tuesday") and half human
 * ("called the customer, no answer"). Split across two tables they would be
 * read interleaved anyway, and every reader would have to merge them.
 *
 * Tenant data by nature — it quotes a record's values and names the person who
 * changed them — so it is relocated into `tenant` and put under the same
 * Row-Level Security as the records it talks about. Mirrors
 * {@see 2026_07_30_900000_create_app_notifications_table}.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'record_events';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable(self::TABLE)) {
            Schema::connection($this->connection)->create(self::TABLE, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('app_id');
                $table->string('record_id');
                // Kept so a deleted record's history can still be read, and so
                // an object's whole trail can be queried without joining.
                $table->string('object_definition_id');

                // 'created' | 'updated' | 'deleted' | 'comment'
                $table->string('kind', 20);

                // Who. Nullable: a workflow or an integration changes records
                // with nobody behind it, and "system" is the honest answer.
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name')->nullable();

                // What a person said. Null for a machine entry.
                $table->text('body')->nullable();

                // What changed, as {field_slug: {from, to}}. Null for a comment.
                // Stored rather than derived: the manifest can rename or drop
                // the field later and the trail must still read.
                $table->jsonb('changes')->nullable();

                $table->timestamps();

                $table->index(['app_id', 'record_id', 'created_at'], 'record_events_trail_idx');
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
