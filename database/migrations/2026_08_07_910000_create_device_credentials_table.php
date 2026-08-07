<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The key a device holds that says who is using it.
 *
 * For the action somebody should have to mean — a refund, a write-off, a
 * deletion. A confirm dialog asks whether you meant it; this asks who you are,
 * with the fingerprint or the face the device already knows.
 *
 * Nothing secret is stored: a public key, the credential's id, and a counter.
 * The private half never leaves the authenticator and cannot be exported from
 * it, which is the entire reason this is worth more than a re-typed password —
 * a stolen copy of this table lets nobody approve anything.
 *
 * Tenant data: it says which person is trusted to approve what inside one
 * organization's app. Same RLS as the records they will be approving.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'device_credentials';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable(self::TABLE)) {
            Schema::connection($this->connection)->create(self::TABLE, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                // Per app, like a push subscription: somebody who enrolled a
                // finger to approve refunds in one app has not agreed to
                // approve anything in the next one the organization builds.
                $table->string('app_id');

                // What the browser calls this credential, base64url.
                $table->string('credential_id', 500);

                // SubjectPublicKeyInfo, base64. Handed over by the browser
                // already encoded, so nothing here parses CBOR.
                $table->text('public_key');

                // Reported by the authenticator. Most platform ones always say
                // zero; a value that goes BACKWARDS is a cloned key.
                $table->unsignedBigInteger('sign_count')->default(0);

                // Something a person recognises in a list of their devices.
                $table->string('label')->nullable();

                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['app_id', 'credential_id'], 'device_creds_unique');
                $table->index(['app_id', 'user_id'], 'device_creds_app_user_idx');
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
