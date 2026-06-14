<?php

namespace App\Jobs\Chat;

use App\Events\Chat\ChatAgentStarted;
use App\Events\Chat\ChatStreamError;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Streams one mentioned agent's turn in a multi-agent thread. Dispatched in a
 * Bus::chain (one per agent, in mention order) so each agent sees the prior
 * agents' completed responses as context. The chain's tail is SynthesizeThread.
 *
 * Runs on the dedicated `agent-responses` queue. Tenant scope is restored from
 * the job payload by the global queue hook (AppServiceProvider).
 */
class InvokeAgentResponse implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $chatId,
        public string $agentId,
        public string $userText,
    ) {}

    public function viaQueue(): string
    {
        return 'agent-responses';
    }

    public function handle(ChatAiService $service): void
    {
        $chat = Chat::query()->find($this->chatId);
        if ($chat === null) {
            Log::warning('InvokeAgentResponse: chat disappeared', ['chat_id' => $this->chatId]);

            return;
        }

        $agent = Agent::query()->find($this->agentId);
        if ($agent === null) {
            Log::warning('InvokeAgentResponse: agent disappeared', [
                'chat_id' => $this->chatId,
                'agent_id' => $this->agentId,
            ]);

            return;
        }

        $placeholder = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => null,
            'model' => $agent->model,
            'status' => 'pending',
            'agent_id' => $agent->id,
            'message_type' => 'text',
        ]);

        try {
            ChatAgentStarted::dispatch($placeholder);
        } catch (Throwable) {
            // Best-effort — the bubble also materializes on the first chunk.
        }

        $service->streamAgentTurn($placeholder, $agent, $this->userText);
    }

    /**
     * Recover a placeholder left mid-flight if the worker itself died (timeout),
     * so the agent bubble doesn't spin forever.
     */
    public function failed(?Throwable $e): void
    {
        $stuck = ChatMessage::query()
            ->where('chat_id', $this->chatId)
            ->where('agent_id', $this->agentId)
            ->whereIn('status', ['pending', 'streaming'])
            ->orderByDesc('created_at')
            ->first();

        if ($stuck === null) {
            return;
        }

        $reason = $e?->getMessage() ?? 'The agent response did not finish in time.';
        $stuck->update(['status' => 'error', 'error' => $reason]);

        Log::error('InvokeAgentResponse failed; placeholder marked error', [
            'chat_id' => $this->chatId,
            'agent_id' => $this->agentId,
            'message_id' => $stuck->id,
            'error' => $reason,
        ]);

        try {
            broadcast(new ChatStreamError($this->chatId, $stuck->id, $reason));
        } catch (Throwable) {
            // swallow
        }
    }
}
