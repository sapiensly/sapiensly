<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People who sign in to a PORTAL — customers, suppliers, applicants — as
 * opposed to the platform's own users.
 *
 * Deliberately NOT rows in `users`. A portal user belongs to ONE app, has no
 * organization membership, no platform roles, no access to anything outside
 * that portal, and no password. Putting them in the same table as staff would
 * make every existing query about "a user" ambiguous, and the first time one of
 * those queries forgot to exclude them would be a breach rather than a bug.
 *
 * Tenant data under RLS: an identity here names a real person and is the key
 * their rows are scoped by.
 *
 * The magic-link token lives on the row rather than in a table of its own:
 * exactly one link is pending at a time, so asking for a new one invalidates
 * the last — which is the behaviour you want anyway, and one fewer place for a
 * live credential to sit.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'portal_users';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable(self::TABLE)) {
            Schema::connection($this->connection)->create(self::TABLE, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('app_id');
                $table->string('email');
                $table->string('name')->nullable();
                // invited → named but never signed in; active → has;
                // blocked → refused whatever the link says.
                $table->string('status')->default('active');

                $table->string('login_token_hash', 64)->nullable();
                $table->timestamp('login_token_expires_at')->nullable();
                $table->timestamp('last_login_at')->nullable();

                $table->timestamps();

                // One identity per person per portal. Two rows for the same
                // address would silently split someone's records in half.
                $table->unique(['app_id', 'email']);
                $table->index('login_token_hash');
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
