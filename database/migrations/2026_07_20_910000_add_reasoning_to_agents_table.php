<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent reasoning preference. Reasoning-capable models otherwise "think"
 * before every reply — paying tokens and latency even for trivial turns — so
 * the platform default is OFF; an agent's owner opts a specific agent back in
 * (low/medium/high) or to the model's own default. NULL means "use the platform
 * default" (off), mirroring how a fresh agent behaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('reasoning')->nullable()->after('web_search');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('reasoning');
        });
    }
};
