<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watermark for the one-time, context-aware title regeneration. Once the
 * conversation matures, the title is refined from the opening exchange and this
 * is stamped so it never fires again — robust against chats that were already
 * past the threshold and against errored/retried turns that skew the count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->timestamp('title_refined_at')->nullable()->after('summary_through_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('title_refined_at');
        });
    }
};
