<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store which Storage disk a chat attachment was saved to. Today every
     * tenant ends up on the global "s3" disk, but per-tenant buckets are on
     * the roadmap — having the disk name on the row means existing
     * attachments keep resolving correctly when tenant config changes.
     */
    public function up(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->string('attachment_disk', 64)->nullable()->after('attachment_mime');
        });
    }

    public function down(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn('attachment_disk');
        });
    }
};
