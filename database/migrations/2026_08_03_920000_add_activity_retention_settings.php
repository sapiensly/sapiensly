<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long an app keeps its activity trail.
 *
 * Two levels, because the two questions are different. An organisation has one
 * answer for "how long do we keep records of who did what" — it comes from
 * their policy, their auditors, their contracts — and it is the same for every
 * app they run. A single app occasionally needs its own: the one holding
 * payroll keeps ten years while the rest keep a month.
 *
 * The default is ONE MONTH, and that is the cost decision. A log of everything
 * with no ceiling is the most expensive table in the system by year three, and
 * the overwhelming majority of tenants will never change this — so the default
 * has to be the cheap one, not the generous one. Anybody who needs longer says
 * so deliberately.
 *
 * Stored in months rather than as an enum: the query is arithmetic, and a
 * column that already means "how many months" needs no translation table.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    private const DEFAULT_MONTHS = 1;

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('organizations', 'activity_retention_months')) {
            Schema::connection($this->connection)->table(
                Schemas::qualify('organizations'),
                function (Blueprint $table) {
                    $table->unsignedSmallInteger('activity_retention_months')
                        ->default(self::DEFAULT_MONTHS);
                }
            );
        }

        if (! Schema::connection($this->connection)->hasColumn('apps', 'activity_retention_months')) {
            Schema::connection($this->connection)->table(
                Schemas::qualify('apps'),
                function (Blueprint $table) {
                    // Null means "whatever the organisation says", which is the
                    // answer for almost every app and keeps one place to change.
                    $table->unsignedSmallInteger('activity_retention_months')->nullable();
                }
            );
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('organizations', 'activity_retention_months')) {
            Schema::connection($this->connection)->table(
                Schemas::qualify('organizations'),
                fn (Blueprint $table) => $table->dropColumn('activity_retention_months')
            );
        }

        if (Schema::connection($this->connection)->hasColumn('apps', 'activity_retention_months')) {
            Schema::connection($this->connection)->table(
                Schemas::qualify('apps'),
                fn (Blueprint $table) => $table->dropColumn('activity_retention_months')
            );
        }
    }
};
