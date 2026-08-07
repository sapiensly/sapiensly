<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where somebody went, while they were looking at the app.
 *
 * The most sensitive data this platform stores, and the only feature here that
 * is OFF unless an owner turns it on — the reverse of `settings.offline`, and
 * for the reverse reason. A location taken to record work and a location taken
 * to watch a person are the same bytes; what separates them is that somebody
 * asked, that the person can see it happening, and that it does not outlive the
 * work it documents.
 *
 * Hence two tables rather than records in the app's own objects: a ping is not
 * business data, it must not appear in the app's tables and exports, and it has
 * to be prunable on its own schedule (`tracking:prune`). A session is what a
 * person STARTED and can stop, and carries the geofence state so an arrival
 * fires once instead of once per fix at the edge of the fence.
 *
 * Tenant schema under RLS, like everything else derived from a person's work.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLES = ['tracking_sessions', 'location_pings'];

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('tracking_sessions')) {
            Schema::connection($this->connection)->create('tracking_sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('app_id');

                // What this session is ABOUT, when it is about something: the
                // work order whose site the geofence is drawn around. A session
                // with no target is a plain trail.
                $table->string('object_id')->nullable();
                $table->string('record_id')->nullable();

                // The fence, resolved when the session started — so moving the
                // record's pin mid-visit does not retroactively change where
                // somebody was judged to have arrived.
                $table->double('target_lat')->nullable();
                $table->double('target_lng')->nullable();
                $table->unsignedInteger('radius_m')->nullable();

                // Whether the last decided reading put them inside. Null until
                // a fix good enough to decide arrives.
                $table->boolean('inside')->nullable();

                $table->timestamp('started_at');
                $table->timestamp('last_ping_at')->nullable();
                // Set when the person stops, or when the pruner gives up on a
                // session nothing has reported to in hours.
                $table->timestamp('ended_at')->nullable();

                $table->timestamps();

                $table->index(['app_id', 'user_id', 'ended_at'], 'tracking_sessions_live_idx');
                $table->index(['app_id', 'record_id'], 'tracking_sessions_record_idx');
            });
        }

        if (! Schema::connection($this->connection)->hasTable('location_pings')) {
            Schema::connection($this->connection)->create('location_pings', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('app_id');
                $table->string('session_id');

                $table->double('lat');
                $table->double('lng');
                $table->unsignedInteger('accuracy_m')->nullable();

                // The app was closed, the phone was locked, the browser slept.
                // Recorded because the SILENCE is the information: without it a
                // straight line across a hole reads as a journey nobody made.
                $table->boolean('gap')->default(false);

                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->index(['session_id', 'recorded_at'], 'location_pings_session_idx');
                // What the pruner scans.
                $table->index(['app_id', 'recorded_at'], 'location_pings_age_idx');
            });
        }

        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $tenant = $this->quoteIdentifier((string) config('tenancy.tenant_role', 'tenant_app'));
        $platform = $this->quoteIdentifier((string) config('tenancy.platform_role', 'platform_app'));

        foreach (self::TABLES as $name) {
            $schema = DB::connection($this->connection)->scalar(
                "select schemaname from pg_tables where tablename = ? and schemaname in ('platform', 'tenant')",
                [$name]
            );

            if ($schema === Schemas::PLATFORM) {
                DB::statement('ALTER TABLE platform.'.$name.' SET SCHEMA tenant');
            }

            $qualified = Schemas::qualify($name);

            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$qualified} TO {$tenant}");
            DB::statement("REVOKE ALL ON {$qualified} FROM {$platform}");

            DB::statement('CREATE INDEX IF NOT EXISTS '.$name.'_tenant_key_idx ON '.$qualified.' (organization_id, user_id)');

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
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
                $qualified = Schemas::qualify($name);
                DB::statement("DROP TRIGGER IF EXISTS fill_tenant_key ON {$qualified}");
                DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$qualified}");
            }

            Schema::connection($this->connection)->dropIfExists(Schemas::qualify($name));
        }
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
