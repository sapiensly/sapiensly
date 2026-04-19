<?php

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds `channel_id` to chatbots and backfills a companion `channels` row per
 * existing chatbot. The chatbot keeps its own `agent_id`/`agent_team_id`
 * columns (to avoid a destructive drop); the Chatbot model gets accessors that
 * read from the related channel going forward so both paths stay consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('channel_id', 36)->nullable()->after('visibility');
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
            $table->index('channel_id');
        });

        DB::transaction(function () {
            DB::table('chatbots')->orderBy('id')->chunkById(100, function ($chatbots) {
                foreach ($chatbots as $chatbot) {
                    $channelId = 'chan_'.strtolower((string) Str::ulid());

                    DB::table('channels')->insert([
                        'id' => $channelId,
                        'user_id' => $chatbot->user_id,
                        'organization_id' => $chatbot->organization_id,
                        'visibility' => $chatbot->visibility,
                        'channel_type' => ChannelType::Widget->value,
                        'name' => $chatbot->name,
                        'agent_id' => $chatbot->agent_id,
                        'agent_team_id' => $chatbot->agent_team_id,
                        'status' => $chatbot->status === 'active'
                            ? ChannelStatus::Active->value
                            : ChannelStatus::Draft->value,
                        'metadata' => null,
                        'created_at' => $chatbot->created_at,
                        'updated_at' => $chatbot->updated_at,
                    ]);

                    DB::table('chatbots')->where('id', $chatbot->id)->update([
                        'channel_id' => $channelId,
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        // Mirror: drop the channels rows for widget-type channels linked to
        // chatbots, then the FK column. Only destructive in the narrow sense
        // that the companion Channel rows are deleted; the Chatbot still
        // carries agent_id/agent_team_id so the rollback is safe.
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropIndex(['channel_id']);
            $table->dropColumn('channel_id');
        });

        DB::table('channels')->where('channel_type', ChannelType::Widget->value)->delete();
    }
};
