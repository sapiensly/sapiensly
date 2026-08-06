<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a build was told it got something wrong.
 *
 * `ai_usage_events` records what a build COST. Nothing records whether it came
 * out right, so the only way to notice a recurring defect is for somebody to
 * read two conversations by hand and spot it — which is exactly how the last
 * round of rails got written (the page-index rejections, the signature stored
 * as text). That does not scale past a sample of two, and it never answers
 * "what fails most often?".
 *
 * The three signals are already produced, already classified, and already
 * thrown away after one turn:
 *   - patch_rejected — propose_change refused the ops (the model believed
 *     something about the manifest that was not true)
 *   - design_smell   — the patch applied, but the validator warned
 *   - critic         — the closing review's `missing` / `unrequested`
 *
 * This is a log, not a knowledge base: nothing reads it back into a prompt. It
 * exists so a human can mine recurring patterns into DETERMINISTIC rules, and
 * so "is model X better than model Y" is a query instead of a hunch.
 *
 * Tenant data by nature — a critic finding quotes the requester's own words and
 * a rejection quotes the app's page names — so it lives in `tenant` under the
 * same Row-Level Security as the builds it describes. Mirrors
 * {@see 2026_08_03_900000_create_record_events_table}.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const TABLE = 'build_findings';

    private const POLICY = 'tenant_isolation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable(self::TABLE)) {
            Schema::connection($this->connection)->create(self::TABLE, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('app_id');
                $table->string('conversation_id')->nullable();

                // The BUILDER's model, not the critic's — the question this
                // column answers is "which model produces more of these?", and
                // the critic's own spend is already in ai_usage_events.
                $table->string('model')->nullable();

                // 'patch_rejected' | 'design_smell' | 'critic'
                $table->string('signal', 20);

                // The validator's error/warning code, or 'missing'/'unrequested'
                // for the critic. This is the column you GROUP BY.
                $table->string('code', 64)->nullable();

                // Where, as the model was told: the JSON pointer, plus the
                // human location propose_change now attaches to every error
                // ("/pages/0 is the page `refacciones_detail`").
                $table->string('path')->nullable();
                $table->text('at')->nullable();

                // The message the model actually read.
                $table->text('detail');

                $table->timestamps();

                $table->index(['app_id', 'created_at'], 'build_findings_app_idx');
                $table->index(['signal', 'code'], 'build_findings_pattern_idx');
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
