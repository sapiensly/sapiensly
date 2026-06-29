<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * change_summary was string() (VARCHAR 255). Builder agents write descriptive,
 * free-form summaries that routinely exceed 255 chars; the overflow aborted the
 * whole createVersion transaction, so the manifest silently failed to save.
 * Widen to text so a long summary never blocks a version write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->text('change_summary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->string('change_summary')->nullable()->change();
        });
    }
};
