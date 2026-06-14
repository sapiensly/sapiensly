<?php

namespace App\Services\Chat;

use App\Jobs\Chat\InvokeAgentResponse;
use App\Jobs\Chat\SynthesizeThread;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Turns a @mention message into a multi-agent thread: flips the chat into
 * multi_agent mode, rosters the agents, and chains one streaming turn per agent
 * (in mention order, so each sees the prior responses) followed by synthesis.
 *
 * Shared by ChatMessageController (mid-conversation) and ChatController (first
 * message) so the flow is identical on both entry points.
 */
class MultiAgentDispatcher
{
    /**
     * @param  Collection<int, Agent>  $agents
     * @return ChatMessage|null the "agents capped" system notice, if one was added
     */
    public function dispatch(Chat $chat, string $content, Collection $agents, bool $capped): ?ChatMessage
    {
        $chat->update([
            'mode' => 'multi_agent',
            'agent_id' => null,
            'synthesis_status' => 'pending',
        ]);

        foreach ($agents as $agent) {
            ChatParticipant::query()->firstOrCreate(
                ['chat_id' => $chat->id, 'agent_id' => $agent->id],
                ['joined_at' => now()],
            );
        }

        $notice = null;
        if ($capped) {
            $notice = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'system',
                'content' => 'Only the first '.MentionParser::MAX_AGENTS.' mentioned agents will respond.',
                'status' => 'complete',
                'message_type' => 'text',
            ]);
        }

        $jobs = $agents
            ->map(fn (Agent $agent) => new InvokeAgentResponse($chat->id, $agent->id, $content))
            ->all();
        $jobs[] = new SynthesizeThread($chat->id);

        Bus::chain($jobs)->onQueue('agent-responses')->dispatch();

        return $notice;
    }
}
