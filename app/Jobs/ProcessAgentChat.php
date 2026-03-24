<?php

namespace App\Jobs;

use App\Enums\AgentType;
use App\Enums\MessageRole;
use App\Events\AgentStreamChunk;
use App\Events\AgentStreamComplete;
use App\Events\AgentStreamError;
use App\Models\Agent;
use App\Models\Conversation;
use App\Services\AiProviderService;
use App\Services\LLMService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAgentChat implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public Agent $agent,
        public Conversation $conversation,
    ) {
        $this->onQueue('ai');
    }

    public function handle(LLMService $llmService, AiProviderService $aiProviderService): void
    {
        $user = $this->agent->user;
        $aiProviderService->applyRuntimeConfig($user);
        $llmService->setContext($user);

        $messages = $this->conversation->messages()->orderBy('created_at')->get();

        if ($messages->isEmpty() || $messages->last()->role !== MessageRole::User) {
            return;
        }

        try {
            if ($this->agent->type === AgentType::Action && $this->agent->tools()->where('status', 'active')->exists()) {
                $this->handleToolChat($llmService, $messages);
            } else {
                $this->handleStreamChat($llmService, $messages);
            }

            $this->sendBroadcast(new AgentStreamComplete($this->conversation->id));
        } catch (\Exception $e) {
            Log::error('ProcessAgentChat failed', [
                'agent_id' => $this->agent->id,
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendBroadcast(new AgentStreamError($this->conversation->id, $e->getMessage()));
        }
    }

    private function sendBroadcast(object $event): void
    {
        try {
            app(BroadcastManager::class)->event($event);
        } catch (\Exception $e) {
            Log::error('Broadcast failed', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleStreamChat(LLMService $llmService, $messages): void
    {
        // Real streaming: use the SDK generator to get chunks as they arrive
        $ragResult = $llmService->streamChatWithRAGInfo($this->agent, $messages->all());
        $knowledgeBases = $ragResult['knowledge_bases'];

        // Broadcast knowledge base events
        foreach ($knowledgeBases as $kb) {
            $this->sendBroadcast(new AgentStreamChunk(
                $this->conversation->id,
                '',
                'knowledge_base',
                ['name' => $kb['name'], 'id' => $kb['id']],
            ));
        }

        // Stream each chunk from the LLM as it arrives
        $fullContent = '';
        foreach ($ragResult['generator'] as $chunk) {
            $fullContent .= $chunk;
            $this->sendBroadcast(new AgentStreamChunk($this->conversation->id, $chunk));
        }

        // Save complete message
        if ($fullContent !== '') {
            $this->conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => $fullContent,
                'model' => $this->agent->model,
                'metadata' => ! empty($knowledgeBases) ? ['knowledge_bases' => $knowledgeBases] : null,
            ]);
        }
    }

    private function handleToolChat(LLMService $llmService, $messages): void
    {
        $response = $llmService->chatWithTools($this->agent, $messages->all());
        $responseText = $response->text;
        $toolCalls = [];

        foreach ($response->steps ?? [] as $step) {
            if (! empty($step->toolCalls)) {
                foreach ($step->toolCalls as $toolCall) {
                    $toolCalls[] = [
                        'name' => $toolCall->name ?? 'unknown',
                        'id' => $toolCall->id ?? null,
                    ];
                }
            }
        }

        foreach ($toolCalls as $toolCall) {
            $this->sendBroadcast(new AgentStreamChunk(
                $this->conversation->id,
                '',
                'tool_call',
                ['tool' => $toolCall['name']],
            ));
        }

        if ($responseText !== null && $responseText !== '') {
            $this->conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => $responseText,
                'model' => $this->agent->model,
                'metadata' => ! empty($toolCalls) ? ['tool_calls' => $toolCalls] : null,
            ]);

            $this->sendBroadcast(new AgentStreamChunk($this->conversation->id, $responseText));
        }
    }
}
