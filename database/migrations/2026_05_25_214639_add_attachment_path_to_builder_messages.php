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
            // Relative path under storage/app/private — set on user-role
            // messages when the user attaches an image to the chat. The
            // job loads it as a LocalImage and feeds it to Claude.
            $table->string('attachment_path', 500)->nullable()->after('change_summary');
            $table->string('attachment_mime', 100)->nullable()->after('attachment_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_mime']);
        });
    }
};
