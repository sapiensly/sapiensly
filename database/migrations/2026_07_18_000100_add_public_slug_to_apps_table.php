<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public identity for landing pages. App slugs are only unique per-org, so the
 * public unauthenticated route (/l/{public_slug}) needs its own GLOBALLY unique
 * slug — minted when the owner explicitly publishes. `public_slug IS NOT NULL`
 * (+ published_at) IS the publish gate: nothing is publicly reachable unless
 * the owner published it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('public_slug', 80)->nullable()->unique()->after('slug');
            $table->timestamp('published_at')->nullable()->after('public_slug');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['public_slug', 'published_at']);
        });
    }
};
