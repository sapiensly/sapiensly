<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `watermark` cursor used by the integration.poll trigger: the highest
 * item watermark (id / timestamp) seen so far, so each poll fires only for newer
 * items. `last_swept_at` doubles as "last polled at" for poll workflows. Stays a
 * platform table (no RLS) — one row per workflow_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_sweep_states', function (Blueprint $table) {
            $table->string('watermark')->nullable()->after('last_swept_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_sweep_states', function (Blueprint $table) {
            $table->dropColumn('watermark');
        });
    }
};
