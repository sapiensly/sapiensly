<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling summary of a long conversation. `summary` holds the condensed memory
 * of older turns (produced with the `summary_large` AI default) and
 * `summary_through_message_id` is the watermark: the id of the newest message
 * already folded in, so later turns send the summary plus only the verbatim tail
 * instead of every message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('model');
            $table->string('summary_through_message_id', 36)->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_through_message_id']);
        });
    }
};
