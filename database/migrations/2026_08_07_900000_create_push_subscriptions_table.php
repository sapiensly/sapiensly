<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach one person's phone when the app is closed.
 *
 * An app could already tell somebody something — by email, or by a bell they
 * see the next time they open it. Neither reaches a technician who has been
 * handed a job while driving, which is the case the whole offline runtime
 * exists for: the app works in the basement, and nothing tells anyone to go
 * down there.
 *
 * A row is a browser, not a person: the same technician has a phone and a
 * desktop, and each one hands out its own endpoint and its own key pair. So the
 * keys ARE the addressing, and there is nothing here that could be used to
 * reach anybody without them — an endpoint alone is refused by the push
 * service, and the payload is encrypted to `p256dh`/`auth`, which only that
 * browser holds.
 *
 * Tenant data by nature: the endpoint says which person on which device, and
 * `app_id` says what they are working on. Same Row-Level Security as the
 * records they will be notified about. Mirrors
 * {@see 2026_08_06_900000_create_build_findings_table}.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'push_subscriptions';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable(self::TABLE)) {
            Schema::connection($this->connection)->create(self::TABLE, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                // Which app they were in when they agreed. A notification opens
                // where the work is, and somebody who allowed notifications for
                // the field-service app has not agreed to hear from every app
                // the organization ever builds.
                $table->string('app_id');

                // The push service's URL for this browser. Long, and long
                // enough to matter: an index over the text itself is a
                // different shape on every service, so the hash is what is
                // unique and the text is what is used.
                $table->text('endpoint');
                $table->string('endpoint_hash', 64);

                // The browser's own key pair. Without both, a payload cannot be
                // encrypted and the service will not deliver it.
                $table->text('p256dh');
                $table->text('auth');

                $table->timestamps();

                $table->unique(['app_id', 'endpoint_hash'], 'push_subs_endpoint_unique');
                $table->index(['app_id', 'user_id'], 'push_subs_app_user_idx');
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
