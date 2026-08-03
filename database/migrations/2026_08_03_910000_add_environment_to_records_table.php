<?php

use App\Support\Apps\EnvironmentContext;
use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which set of records a row belongs to: what the business runs on, or the
 * sandbox somebody is allowed to wreck.
 *
 * A column and an index, deliberately — not a second schema, not a second app.
 * Two environments share one manifest, because two manifests drift the first
 * time somebody edits a button; what separates is the data and the side
 * effects. The cheap shape is also the correct one here.
 *
 * Every existing row is production, which is what they have always been.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        $qualified = Schemas::qualify('records');

        if (! Schema::connection($this->connection)->hasColumn('records', 'environment')) {
            Schema::connection($this->connection)->table('records', function (Blueprint $table) {
                $table->string('environment', 20)
                    ->default(EnvironmentContext::PRODUCTION)
                    ->after('object_definition_id');
            });
        }

        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        // Every list, board, chart and count filters on this beside the object,
        // so it belongs in the same index rather than in one of its own.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS records_environment_idx ON '.$qualified.
            ' (app_id, object_definition_id, environment)'
        );
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.Schemas::TENANT.'.records_environment_idx');
        }

        if (Schema::connection($this->connection)->hasColumn('records', 'environment')) {
            Schema::connection($this->connection)->table('records', function (Blueprint $table) {
                $table->dropColumn('environment');
            });
        }
    }
};
