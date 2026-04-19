<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds `contact_id` to widget_sessions and backfills a companion `contacts`
 * row per session (identifier = session_token). The widget's session-bound
 * visitor state is now mirrored into the cross-channel Contact abstraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_sessions', function (Blueprint $table) {
            $table->string('contact_id', 36)->nullable()->after('chatbot_id');
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index('contact_id');
        });

        DB::transaction(function () {
            DB::table('widget_sessions')
                ->select('widget_sessions.*', 'chatbots.channel_id as chatbot_channel_id')
                ->leftJoin('chatbots', 'widget_sessions.chatbot_id', '=', 'chatbots.id')
                ->orderBy('widget_sessions.id')
                ->chunkById(100, function ($sessions) {
                    foreach ($sessions as $session) {
                        if (! $session->chatbot_channel_id) {
                            continue; // orphaned session without a chatbot; skip
                        }

                        $contactId = 'cont_'.strtolower((string) Str::ulid());
                        $metadata = $session->visitor_metadata;
                        if (is_string($metadata)) {
                            $decoded = json_decode($metadata, true);
                            $metadata = is_array($decoded) ? $decoded : null;
                        }

                        DB::table('contacts')->insert([
                            'id' => $contactId,
                            'channel_id' => $session->chatbot_channel_id,
                            'identifier' => $session->session_token,
                            'profile_name' => $session->visitor_name,
                            'email' => $session->visitor_email,
                            'phone_e164' => null,
                            'locale' => null,
                            'metadata' => $metadata ? json_encode($metadata) : null,
                            'last_inbound_at' => $session->last_activity_at,
                            'last_outbound_at' => null,
                            'opted_out_at' => null,
                            'user_agent' => $session->user_agent,
                            'ip_address' => $session->ip_address,
                            'created_at' => $session->created_at,
                            'updated_at' => $session->updated_at,
                        ]);

                        DB::table('widget_sessions')
                            ->where('id', $session->id)
                            ->update(['contact_id' => $contactId]);
                    }
                }, 'widget_sessions.id', 'id');
        });
    }

    public function down(): void
    {
        Schema::table('widget_sessions', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropIndex(['contact_id']);
            $table->dropColumn('contact_id');
        });

        // Contact rows whose identifier matches a widget_session token become
        // orphaned; drop them in the rollback.
        DB::table('contacts')->whereIn('channel_id', function ($q) {
            $q->select('channel_id')->from('chatbots');
        })->delete();
    }
};
