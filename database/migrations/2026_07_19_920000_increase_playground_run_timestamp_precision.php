<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle timestamps were second-resolution, so queue wait
 * (started_at − queued_at) quantized to whole seconds — a trivial run reported
 * an exact "4000 ms" wait. Widen queued_at / started_at / finished_at to
 * millisecond precision so `PlaygroundRun::queueWaitMs()` returns the real
 * cross-process wait. `now()` already carries sub-second precision; only the
 * column was truncating it. `duration_ms` (hrtime, in-process) was unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->timestamp('queued_at', 3)->nullable()->change();
            $table->timestamp('started_at', 3)->nullable()->change();
            $table->timestamp('finished_at', 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->change();
            $table->timestamp('started_at')->nullable()->change();
            $table->timestamp('finished_at')->nullable()->change();
        });
    }
};
