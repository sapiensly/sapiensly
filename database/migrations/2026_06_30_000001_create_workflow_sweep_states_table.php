<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workflow cursor for time-based trigger sweeps (record.date_reached).
 * `last_swept_at` advances monotonically each sweep so every record's target
 * moment falls in exactly one contiguous (last_swept_at, now] window — firing
 * once, with no per-record marker. Initialised to "now" on first sight so a
 * new workflow never backfills historical records.
 *
 * Lives in `platform` (control-plane, no RLS) — keyed by the globally-unique
 * workflow_id; deliberately NOT a tenant table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_sweep_states', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('app_id', 36);
            $table->string('workflow_id')->unique();
            $table->timestamp('last_swept_at');
            $table->timestamps();

            $table->index('app_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_sweep_states');
    }
};
