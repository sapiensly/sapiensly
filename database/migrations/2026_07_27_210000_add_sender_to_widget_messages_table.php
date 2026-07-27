<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHO produced an assistant message on the widget: the bot, or a person.
 *
 * Mirrors `whatsapp_messages.sender_user_id`, which has carried this since that
 * channel got human takeover. Without it a human reply is indistinguishable from
 * a model reply in the transcript, and the two must never be confused — not in
 * the operator's view of what was said on their behalf, not in the analytics
 * that measure how the BOT performed, and not to the visitor, who is owed the
 * truth about whether they are talking to a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_messages', function (Blueprint $table) {
            $table->foreignId('sender_user_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('widget_messages', function (Blueprint $table) {
            $table->dropForeign(['sender_user_id']);
            $table->dropColumn('sender_user_id');
        });
    }
};
