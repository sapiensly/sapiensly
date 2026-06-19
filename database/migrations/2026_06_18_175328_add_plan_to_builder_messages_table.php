<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            // Structured plan proposal (trigger, ordered steps, external
            // touches read/write, assumptions). Set on an assistant turn that
            // proposes a build BEFORE editing the manifest — the user approves,
            // edits or discards it from the plan card. Null for plain turns.
            $table->jsonb('plan')->nullable()->after('change_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
