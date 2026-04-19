<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `channel_id` + `contact_id` to widget_conversations and backfills
 * them from the owning chatbot/session. Makes widget_conversations first-
 * class citizens of the cross-channel Contact abstraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->string('channel_id', 36)->nullable()->after('chatbot_id');
            $table->string('contact_id', 36)->nullable()->after('widget_session_id');

            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();

            $table->index('channel_id');
            $table->index('contact_id');
        });

        DB::transaction(function () {
            DB::table('widget_conversations')
                ->select(
                    'widget_conversations.id',
                    'chatbots.channel_id as chatbot_channel_id',
                    'widget_sessions.contact_id as session_contact_id',
                )
                ->leftJoin('chatbots', 'widget_conversations.chatbot_id', '=', 'chatbots.id')
                ->leftJoin('widget_sessions', 'widget_conversations.widget_session_id', '=', 'widget_sessions.id')
                ->orderBy('widget_conversations.id')
                ->chunkById(200, function ($conversations) {
                    foreach ($conversations as $conv) {
                        DB::table('widget_conversations')
                            ->where('id', $conv->id)
                            ->update([
                                'channel_id' => $conv->chatbot_channel_id,
                                'contact_id' => $conv->session_contact_id,
                            ]);
                    }
                }, 'widget_conversations.id', 'id');
        });
    }

    public function down(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropForeign(['contact_id']);
            $table->dropIndex(['channel_id']);
            $table->dropIndex(['contact_id']);
            $table->dropColumn(['channel_id', 'contact_id']);
        });
    }
};
