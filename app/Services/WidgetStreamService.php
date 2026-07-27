<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Services\Ai\AiDefaults;
use App\Services\Chatbots\ConversationEscalator;
use App\Support\Ai\AiUsageSubject;
use App\Support\Ai\PublicTurnContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles SSE streaming for widget chat responses.
 *
 * Streams a single agent or a Bot Flow roster,
 * integrating with LLMService and TeamOrchestrationService.
 */
class WidgetStreamService
{
    public function __construct(
        private LLMService $llmService,
        private TeamOrchestrationService $orchestrationService,
        private AiDefaults $aiDefaults,
        private ConversationAttachmentService $attachments,
        private ConversationEscalator $escalator,
    ) {}

    /**
     * Normalized descriptors for the files attached to the conversation's most
     * recent user message — the turn the bot is about to answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function turnAttachments(WidgetConversation $conversation): array
    {
        // reorder() before latest(), for the second time in this file: the
        // messages() relation bakes in an ASCENDING order for the transcript and
        // a chained latest() only appends to it, so without this "the visitor's
        // last message" resolves to their FIRST one. Here that meant a file
        // attached on turn three never reached the model, while turn one's file
        // was re-sent on every turn forever.
        $lastUser = $conversation->messages()
            ->where('role', MessageRole::User->value)
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->first();

        if ($lastUser === null) {
            return [];
        }

        $descriptors = [];
        foreach ($lastUser->attachments as $att) {
            $descriptors[] = $this->attachments->descriptor(
                $att->id,
                $att->original_name,
                $att->mime,
                $att->disk,
                $att->storage_path,
                $att->extracted_text,
            );
        }

        return $descriptors;
    }

    /**
     * Stream an AI response for a widget conversation.
     */
    public function stream(
        Chatbot $chatbot,
        WidgetConversation $conversation
    ): StreamedResponse {
        $startTime = microtime(true);

        // Bind the AI provider credentials to the chatbot's OWNER, exactly as
        // BindWidgetTenantContext binds their tenant scope. A widget request has
        // no session user, and InjectAiProviderConfig only runs on the `web`
        // group and only for an authenticated one — so without this the DB-stored
        // keys never reach the SDK and every provider call fails auth. Invisible
        // on installs whose keys sit in the environment, fatal on the ones whose
        // keys live in `ai_providers`. setContext also attributes the spend to
        // the owner, which is who is paying for an anonymous visitor's turn.
        if (($owner = $chatbot->user) !== null) {
            $this->llmService->setContext($owner);
        }

        // Get conversation messages for context
        $messages = $conversation->messages()->orderBy('created_at')->get();

        // Two things are true of this turn and neither belongs on this service:
        //
        //  - it is driven by a STRANGER, so it must not inherit the owner's
        //    platform tool catalogue, only the tools attached to this bot;
        //  - it belongs to THIS bot's conversation, so its spend is attributable
        //    instead of vanishing into the organization's total.
        //
        // Both ride on request-scoped contexts because a multi-agent bot answers
        // through the orchestrator's own LLMService instance, which any flag set
        // on this one would sail straight past.
        return app(PublicTurnContext::class)->runPublic(
            fn () => app(AiUsageSubject::class)->attributedTo(
                'chatbot',
                $conversation->id,
                fn () => $this->answer($chatbot, $conversation, $messages, $startTime),
            ),
            $chatbot,
            $conversation,
        );
    }

    /**
     * Pick how this bot answers and run it: a one-agent roster is direct LLM
     * chat, more than one goes through orchestration.
     *
     * @param  Collection<int, WidgetMessage>  $messages
     */
    private function answer(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        $messages,
        float $startTime,
    ): StreamedResponse {
        // The AI Bot runs on its Bot Flow roster. A single-agent roster runs as
        // direct LLM chat; a multi-agent roster goes through orchestration.
        $roster = $chatbot->botFlow?->rosterAgents() ?? [];

        if (count($roster) === 1) {
            return $this->streamAgentResponse($chatbot, $conversation, $roster[0], $messages, $startTime);
        }

        if (count($roster) > 1) {
            return $this->streamBotFlowResponse($chatbot, $conversation, $chatbot->botFlow, $messages, $startTime);
        }

        // No agents in the flow yet.
        return $this->createStreamResponse($conversation, 'unknown', [], 'No agent configured for this bot.', $startTime);
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

        // Fall back to the chatbots default model when the agent pins none.
        if (empty($agent->model)) {
            $agent->model = $this->aiDefaults->model('chatbots');
        }

        try {
            Log::info('Widget: Starting agent stream', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
            ]);

            // Files the visitor attached this turn, as SDK stored files (images
            // for vision, documents for the model to read).
            $descriptors = $this->turnAttachments($conversation);
            $storedAttachments = array_map(
                fn (array $descriptor) => $this->attachments->toStoredFile($descriptor),
                $descriptors,
            );

            // One path for both bots, with and without tools, because the answer
            // is built the same way either way: retrieve, put the retrieved text
            // INTO the prompt, then answer.
            //
            // It used to fork, and both forks were ungrounded. The tool branch
            // never retrieved at all. The other retrieved, kept only the list of
            // knowledge bases for the "consulted X" chip and the stored metadata,
            // and dropped `context` on the floor — so the widget paid for the
            // embedding and the vector search, told the visitor their question
            // had been researched, and then answered from the bare prompt. Worse
            // than not searching: it looked grounded.
            //
            // Synchronous on purpose: streaming generators do not survive inside
            // response()->stream(), which is why this path never used the
            // streaming RAG helper.
            $result = $this->llmService->chatWithKnowledgeAndTools(
                $agent,
                $messages->all(),
                attachments: $storedAttachments,
            );

            $response = $result['response'];
            $knowledgeBases = $result['knowledge_bases'];

            $text = $response->text ?? '';
            if ($text !== '') {
                $chunks[] = $text;
            }

            foreach ($response->steps ?? [] as $step) {
                foreach ($step->toolCalls ?? [] as $toolCall) {
                    $toolCalls[] = [
                        'name' => $toolCall->name ?? 'unknown',
                        'id' => $toolCall->id ?? null,
                    ];
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
     * Stream a response by orchestrating the AI Bot's Bot Flow roster.
     */
    private function streamBotFlowResponse(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        BotFlow $flow,
        $messages,
        float $startTime
    ): StreamedResponse {
        $attachments = $this->turnAttachments($conversation);

        return $this->consumeOrchestration(
            $chatbot,
            $conversation,
            $messages,
            $flow->roster()['triage']?->model ?? 'unknown',
            $startTime,
            fn (Conversation $temp, string $userMessage) => $this->orchestrationService->orchestrateBotFlow($flow, $temp, $userMessage, $attachments),
            'flow_id',
            $flow->id,
        );
    }

    /**
     * Drive an orchestration generator into a streamed SSE response. Shared by
     * the legacy team path and the Bot Flow roster path.
     *
     * @param  callable(Conversation, string): \Generator  $orchestrate
     */
    private function consumeOrchestration(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        $messages,
        string $model,
        float $startTime,
        callable $orchestrate,
        string $sourceKey,
        string $sourceId
    ): StreamedResponse {
        $chunks = [];
        $events = [];
        $error = null;

        try {
            Log::info('Widget: Starting orchestration stream', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                $sourceKey => $sourceId,
            ]);

            $userMessage = $messages->last()?->content ?? '';

            // Create a temporary conversation-like object for orchestration
            $tempConversation = new Conversation([
                'id' => $conversation->id,
            ]);
            $tempConversation->setRelation('messages', $messages);

            foreach ($orchestrate($tempConversation, $userMessage) as $event) {
                if ($event['type'] === 'content') {
                    $chunks[] = $event['content'] ?? '';
                } else {
                    $events[] = $event;
                }
            }
        } catch (\Exception $e) {
            Log::error('Widget: Orchestration stream error', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                $sourceKey => $sourceId,
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
            } elseif ($event['type'] === 'flow_human_handoff') {
                // A human_handoff node fired. This used to write a metadata key
                // that nothing in the codebase ever read — the visitor was told
                // help was coming and no one was told anything at all.
                $this->escalator->escalate(
                    $chatbot,
                    $conversation,
                    reason: $event['reason'] ?? null,
                    notify: (bool) ($event['notify'] ?? true),
                );
            }
        }

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
    /**
     * Store the finished reply and its conversation bookkeeping. Returns the
     * message, or null when the model produced nothing to store.
     *
     * @param  list<string>  $chunks
     * @param  array<int, array<string, mixed>>  $knowledgeBases
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function persistAssistantMessage(
        WidgetConversation $conversation,
        string $model,
        array $chunks,
        float $startTime,
        array $knowledgeBases,
        array $toolCalls,
    ): ?WidgetMessage {
        $fullContent = implode('', $chunks);
        if ($fullContent === '') {
            return null;
        }

        $metadata = [];
        if (! empty($knowledgeBases)) {
            $metadata['knowledge_bases'] = $knowledgeBases;
        }
        if (! empty($toolCalls)) {
            $metadata['tool_calls'] = $toolCalls;
        }

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $message = WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => $fullContent,
            'model' => $model,
            'response_time_ms' => $responseTimeMs,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);

        $conversation->increment('message_count');

        if (! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }

        $conversation->increment('total_response_time_ms', $responseTimeMs);

        return $message;
    }

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
        // Persist BEFORE transmitting. The model call already finished and was
        // already paid for by the time we get here — $chunks is the whole reply.
        // Saving it inside the streaming callback meant a visitor closing the tab
        // mid-transmission aborted the script before the write: the tokens were
        // spent, the answer vanished, and on their next visit the transcript
        // showed their question with no reply to it.
        if ($error === null) {
            $this->persistAssistantMessage($conversation, $model, $chunks, $startTime, $knowledgeBases, $toolCalls);
        }

        return response()->stream(function () use (
            $chunks, $error, $knowledgeBases, $toolCalls, $events
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

            // The `type` is load-bearing, not decoration: the widget dispatches
            // on `event.type === 'content'`, so an untyped payload is parsed,
            // matched by nothing and dropped. The reply was still persisted, so
            // it surfaced on the next page load — which made the bot look like
            // it only answered after a refresh.
            foreach ($chunks as $chunk) {
                $this->sendEvent(['type' => 'content', 'content' => $chunk]);
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
