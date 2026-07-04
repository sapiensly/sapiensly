<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('kind', 20)->default('app')->after('color');
        });

        // No data backfill here on purpose: running Eloquent (platform
        // connection / RLS) inside a migration is fragile. Existing apps keep
        // the 'app' default and are re-classified automatically the next time a
        // version is written (AppManifestService::createVersion syncs `kind`),
        // or in bulk via `apps:reclassify-kind`.
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
