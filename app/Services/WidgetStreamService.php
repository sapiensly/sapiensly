<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles SSE streaming for widget chat responses.
 *
 * Supports both single Agent and AgentTeam targets,
 * integrating with LLMService and TeamOrchestrationService.
 */
class WidgetStreamService
{
    public function __construct(
        private LLMService $llmService,
        private TeamOrchestrationService $orchestrationService
    ) {}

    /**
     * Stream an AI response for a widget conversation.
     */
    public function stream(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        Agent|AgentTeam $target
    ): StreamedResponse {
        $startTime = microtime(true);

        // Get conversation messages for context
        $messages = $conversation->messages()->orderBy('created_at')->get();

        if ($target instanceof AgentTeam) {
            return $this->streamTeamResponse($chatbot, $conversation, $target, $messages, $startTime);
        }

        return $this->streamAgentResponse($chatbot, $conversation, $target, $messages, $startTime);
    }

    /**
     * Stream response from a single agent.
     */
    private function streamAgentResponse(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        Agent $agent,
        $messages,
        float $startTime
    ): StreamedResponse {
        $chunks = [];
        $knowledgeBases = [];
        $toolCalls = [];
        $error = null;

        try {
            Log::info('Widget: Starting agent stream', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
            ]);

            // Check if agent has active tools
            if ($agent->tools()->where('status', 'active')->exists()) {
                // Use tool-enabled chat (non-streaming)
                $response = $this->llmService->chatWithTools($agent, $messages->all());
                $chunks[] = $response->text ?? '';

                // Extract tool calls
                foreach ($response->steps ?? [] as $step) {
                    foreach ($step->toolCalls ?? [] as $toolCall) {
                        $toolCalls[] = [
                            'name' => $toolCall->name ?? 'unknown',
                            'id' => $toolCall->id ?? null,
                        ];
                    }
                }
            } else {
                // Use synchronous chat (streaming generators don't work inside response()->stream())
                $knowledgeBaseIds = $agent->knowledgeBases()->pluck('knowledge_bases.id')->toArray();

                if (! empty($knowledgeBaseIds)) {
                    $lastUserMessage = $messages->last()?->content ?? '';
                    $retrieval = app(RetrievalService::class)->retrieve(
                        $lastUserMessage,
                        $knowledgeBaseIds,
                        topK: 5,
                        threshold: 0.5
                    );
                    $knowledgeBases = $retrieval['knowledge_bases'] ?? [];
                }

                $fullContent = $this->llmService->chat($agent, $messages->all());
                if ($fullContent !== '') {
                    $chunks[] = $fullContent;
                }
            }
        } catch (\Exception $e) {
            Log::error('Widget: Agent stream error', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
            $error = $e->getMessage();
        }

        return $this->createStreamResponse(
            $conversation,
            $agent->model,
            $chunks,
            $error,
            $startTime,
            $knowledgeBases,
            $toolCalls
        );
    }

    /**
     * Stream response from an agent team.
     */
    private function streamTeamResponse(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        AgentTeam $team,
        $messages,
        float $startTime
    ): StreamedResponse {
        $chunks = [];
        $events = [];
        $error = null;

        try {
            Log::info('Widget: Starting team stream', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'team_id' => $team->id,
            ]);

            // Get the last user message
            $lastUserMessage = $messages->last();
            $userMessage = $lastUserMessage?->content ?? '';

            // Create a temporary conversation-like object for orchestration
            $tempConversation = new Conversation([
                'id' => $conversation->id,
            ]);
            $tempConversation->setRelation('messages', $messages);

            // Run orchestration
            foreach ($this->orchestrationService->orchestrate($team, $tempConversation, $userMessage) as $event) {
                if ($event['type'] === 'content') {
                    $chunks[] = $event['content'] ?? '';
                } else {
                    $events[] = $event;
                }
            }
        } catch (\Exception $e) {
            Log::error('Widget: Team stream error', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);
            $error = $e->getMessage();
        }

        // Extract knowledge bases and tool calls from events
        $knowledgeBases = [];
        $toolCalls = [];
        foreach ($events as $event) {
            if ($event['type'] === 'knowledge_base') {
                $knowledgeBases[] = [
                    'name' => $event['name'] ?? '',
                    'id' => $event['id'] ?? null,
                ];
            } elseif ($event['type'] === 'tool_call') {
                $toolCalls[] = [
                    'name' => $event['tool'] ?? 'unknown',
                ];
            }
        }

        $model = $team->triageAgent?->model ?? 'unknown';

        return $this->createStreamResponse(
            $conversation,
            $model,
            $chunks,
            $error,
            $startTime,
            $knowledgeBases,
            $toolCalls,
            $events
        );
    }

    /**
     * Create a streamed SSE response.
     */
    private function createStreamResponse(
        WidgetConversation $conversation,
        string $model,
        array $chunks,
        ?string $error,
        float $startTime,
        array $knowledgeBases = [],
        array $toolCalls = [],
        array $events = []
    ): StreamedResponse {
        return response()->stream(function () use (
            $conversation, $model, $chunks, $error, $startTime,
            $knowledgeBases, $toolCalls, $events
        ) {
            // Send tool call events first
            foreach ($toolCalls as $toolCall) {
                $this->sendEvent([
                    'type' => 'tool_call',
                    'tool' => $toolCall['name'],
                ]);
            }

            // Send knowledge base events
            foreach ($knowledgeBases as $kb) {
                $this->sendEvent([
                    'type' => 'knowledge_base',
                    'name' => $kb['name'],
                    'id' => $kb['id'],
                ]);
            }

            // Send other orchestration events (execution_plan, step_start, etc.)
            foreach ($events as $event) {
                if (! in_array($event['type'], ['content', 'knowledge_base', 'tool_call'])) {
                    $this->sendEvent($event);
                }
            }

            if ($error) {
                $this->sendEvent(['error' => $error]);

                return;
            }

            // Stream content chunks
            $fullContent = '';
            foreach ($chunks as $chunk) {
                $fullContent .= $chunk;
                $this->sendEvent(['content' => $chunk]);
            }

            // Calculate response time
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Save the assistant message
            if ($fullContent !== '') {
                $metadata = [];
                if (! empty($knowledgeBases)) {
                    $metadata['knowledge_bases'] = $knowledgeBases;
                }
                if (! empty($toolCalls)) {
                    $metadata['tool_calls'] = $toolCalls;
                }

                $message = WidgetMessage::create([
                    'widget_conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant,
                    'content' => $fullContent,
                    'model' => $model,
                    'response_time_ms' => $responseTimeMs,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                ]);

                $conversation->increment('message_count');

                // Update first response time if this is the first assistant message
                if (! $conversation->first_response_at) {
                    $conversation->update([
                        'first_response_at' => now(),
                    ]);
                }

                // Accumulate total response time
                $conversation->increment('total_response_time_ms', $responseTimeMs);
            }

            $this->sendEvent(['type' => 'done']);
            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, $this->streamHeaders());
    }

    /**
     * Send an SSE event.
     */
    private function sendEvent(array $data): void
    {
        echo 'data: '.json_encode($data)."\n\n";
        $this->flushOutput();
    }

    /**
     * Get SSE stream headers.
     */
    private function streamHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Flush output buffers safely.
     */
    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
