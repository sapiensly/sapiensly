<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activity logging is off unless somebody asks for it.
 *
 * It shipped defaulting to one month, on the reasoning that the cheapest
 * period should be the default. Off is cheaper still, and it is the honest
 * default for a different reason: an activity trail records who did what, and
 * turning that on is a decision a business makes deliberately — about its own
 * people, its policy and its auditors. It is not something a platform should
 * start doing to them because nobody said no.
 *
 * Zero months rather than a separate flag: "how long do we keep it" already
 * has room for "we don't", and one column with one meaning beats two that can
 * disagree about whether a trail is on but kept for no time.
 *
 * Rows still holding the old default of 1 are moved to 0. Safe because that
 * default has existed for hours and nobody has had the chance to choose it;
 * anybody who HAS picked a period keeps it.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    /** What the column defaulted to before this. */
    private const PREVIOUS_DEFAULT = 1;

    public function up(): void
    {
        foreach (['organizations', 'apps'] as $table) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'activity_retention_months')) {
                continue;
            }

            $qualified = Schemas::qualify($table);

            DB::statement("ALTER TABLE {$qualified} ALTER COLUMN activity_retention_months SET DEFAULT 0");

            // Only the ones nobody chose. An app's column is nullable and
            // means "ask the organisation", so it is left alone entirely.
            if ($table === 'organizations') {
                DB::table($qualified)
                    ->where('activity_retention_months', self::PREVIOUS_DEFAULT)
                    ->update(['activity_retention_months' => 0]);
            }
        }
    }

    public function down(): void
    {
        foreach (['organizations', 'apps'] as $table) {
            if (Schema::connection($this->connection)->hasColumn($table, 'activity_retention_months')) {
                DB::statement(
                    'ALTER TABLE '.Schemas::qualify($table).
                    ' ALTER COLUMN activity_retention_months SET DEFAULT '.self::PREVIOUS_DEFAULT
                );
            }
        }
    }
};
