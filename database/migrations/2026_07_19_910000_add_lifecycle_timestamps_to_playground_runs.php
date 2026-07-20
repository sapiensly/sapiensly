<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playground runs become an async state machine (queued → running → ok|error):
 * execution moves off the HTTP worker into a queued job, so the row needs
 * lifecycle timestamps. Telemetry stays exact: `duration_ms` keeps measuring
 * only the provider execution, while queue wait is derivable from
 * queued_at → started_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->dropColumn(['queued_at', 'started_at', 'finished_at']);
        });
    }
};
