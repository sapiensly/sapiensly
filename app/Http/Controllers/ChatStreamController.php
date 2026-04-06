<?php

namespace App\Http\Controllers;

use App\Enums\AgentType;
use App\Enums\FlowActionType;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Flow;
use App\Services\FlowAction;
use App\Services\FlowExecutorService;
use App\Services\LLMService;
use App\Services\RetrievalService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController extends Controller
{
    public function __construct(
        private LLMService $llmService,
        private FlowExecutorService $flowExecutor,
    ) {}

    public function stream(Request $request, Agent $agent, Conversation $conversation): StreamedResponse
    {
        // Verify access
        $this->authorize('view', $agent);

        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        // Get conversation messages
        $messages = $conversation->messages()->orderBy('created_at')->get();

        // Validate we have messages to send
        if ($messages->isEmpty()) {
            return response()->stream(function () {
                echo 'data: '.json_encode(['error' => 'No messages in conversation'])."\n\n";
                $this->flushOutput();
            }, 200, $this->streamHeaders());
        }

        // Ensure the last message is from a user (otherwise nothing to respond to)
        $lastMessage = $messages->last();
        if ($lastMessage->role !== MessageRole::User) {
            return response()->stream(function () {
                echo 'data: '.json_encode(['error' => 'Last message must be from user'])."\n\n";
                $this->flushOutput();
            }, 200, $this->streamHeaders());
        }

        // Check for active flow on Triage agents
        if ($agent->type === AgentType::Triage) {
            $flowState = $conversation->metadata['flow_state'] ?? null;
            $userMessage = $lastMessage->content;

            if ($this->flowExecutor->shouldActivateFlow($agent, $userMessage, $flowState)) {
                return $this->streamFlow($agent, $conversation, $userMessage, $flowState);
            }
        }

        // Route Action Agents with tools to tool-enabled chat
        if ($agent->type === AgentType::Action && $agent->tools()->where('status', 'active')->exists()) {
            return $this->streamWithTools($agent, $conversation, $messages);
        }

        // Default: RAG-enabled streaming for Knowledge agents or agents without tools
        return $this->streamWithRAG($agent, $conversation, $messages);
    }

    /**
     * Stream response with tool calling for Action Agents.
     *
     * Tool calling uses non-streaming mode because the AI SDK executes
     * tools synchronously. The final response is then sent to the client.
     */
    private function streamWithTools(Agent $agent, Conversation $conversation, Collection $messages): StreamedResponse
    {
        return response()->stream(function () use ($agent, $conversation, $messages) {
            try {
                \Log::info('Starting tool-enabled chat', [
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                ]);

                $response = $this->llmService->chatWithTools($agent, $messages->all());
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

                // Send tool call events
                foreach ($toolCalls as $toolCall) {
                    echo 'data: '.json_encode([
                        'type' => 'tool_call',
                        'tool' => $toolCall['name'],
                    ])."\n\n";
                    $this->flushOutput();
                }

                if ($responseText !== null && $responseText !== '') {
                    echo 'data: '.json_encode(['content' => $responseText])."\n\n";
                    $this->flushOutput();

                    $conversation->messages()->create([
                        'role' => MessageRole::Assistant,
                        'content' => $responseText,
                        'model' => $agent->model,
                        'metadata' => ! empty($toolCalls) ? ['tool_calls' => $toolCalls] : null,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('Tool Chat Error', [
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                echo 'data: '.json_encode(['error' => $e->getMessage()])."\n\n";
                $this->flushOutput();
            }

            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, $this->streamHeaders());
    }

    /**
     * Stream response with RAG (Retrieval Augmented Generation).
     */
    private function streamWithRAG(Agent $agent, Conversation $conversation, Collection $messages): StreamedResponse
    {
        // Collect the full response first, then stream it to the client.
        // The Laravel AI SDK's stream() generator does not yield inside
        // response()->stream() closures due to output-buffering conflicts,
        // so we use the synchronous chat methods instead.
        $knowledgeBases = [];
        $fullContent = '';
        $error = null;

        try {
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
        } catch (\Exception $e) {
            \Log::error('LLM Chat Error', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            $error = $e->getMessage();
        }

        return response()->stream(function () use ($agent, $conversation, $knowledgeBases, $fullContent, $error) {
            foreach ($knowledgeBases as $kb) {
                echo 'data: '.json_encode([
                    'type' => 'knowledge_base',
                    'name' => $kb['name'],
                    'id' => $kb['id'],
                ])."\n\n";
                $this->flushOutput();
            }

            if ($error) {
                echo 'data: '.json_encode(['error' => $error])."\n\n";
                $this->flushOutput();
            } elseif ($fullContent !== '') {
                echo 'data: '.json_encode(['content' => $fullContent])."\n\n";
                $this->flushOutput();

                $conversation->messages()->create([
                    'role' => MessageRole::Assistant,
                    'content' => $fullContent,
                    'model' => $agent->model,
                    'metadata' => ! empty($knowledgeBases) ? ['knowledge_bases' => $knowledgeBases] : null,
                ]);
            }

            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, $this->streamHeaders());
    }

    /**
     * Stream flow execution for Triage agents with active flows.
     */
    private function streamFlow(Agent $agent, Conversation $conversation, string $userMessage, ?array $flowState): StreamedResponse
    {
        $flow = $agent->activeFlow();
        if (! $flow) {
            return $this->streamWithRAG($agent, $conversation, $conversation->messages()->orderBy('created_at')->get());
        }

        // Initialize or continue flow
        if ($flowState === null || ($flowState['completed'] ?? false)) {
            $flowState = $this->flowExecutor->initializeFlow($flow);
        }

        $action = $this->flowExecutor->processInput($flow, $flowState, $userMessage);

        // Persist flow state
        $metadata = $conversation->metadata ?? [];
        $metadata['flow_state'] = $action->updatedState;
        $conversation->update(['metadata' => $metadata]);

        return response()->stream(function () use ($action, $flow, $conversation) {
            try {
                $this->emitFlowEvents($action, $flow, $conversation);
            } catch (\Exception $e) {
                \Log::error('Flow stream error', ['error' => $e->getMessage()]);
                echo 'data: '.json_encode(['error' => $e->getMessage()])."\n\n";
                $this->flushOutput();
            }

            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, $this->streamHeaders());
    }

    private function emitFlowEvents(FlowAction $action, Flow $flow, Conversation $conversation): void
    {
        match ($action->type) {
            FlowActionType::ShowMenu => $this->emitEvent([
                'type' => 'flow_menu',
                'message' => $action->data['message'] ?? '',
                'options' => $action->data['options'] ?? [],
            ]),
            FlowActionType::SendMessage => (function () use ($action, $flow, $conversation) {
                $this->emitEvent(['type' => 'flow_message', 'content' => $action->data['message'] ?? '']);

                // Save as assistant message
                $conversation->messages()->create([
                    'role' => MessageRole::Assistant,
                    'content' => $action->data['message'] ?? '',
                ]);

                // Auto-advance to next node if available
                if (isset($action->data['next_node_id'])) {
                    $nextAction = $this->flowExecutor->advanceToNode($flow, $action->updatedState, $action->data['next_node_id']);
                    $metadata = $conversation->metadata ?? [];
                    $metadata['flow_state'] = $nextAction->updatedState;
                    $conversation->update(['metadata' => $metadata]);
                    $this->emitFlowEvents($nextAction, $flow, $conversation);
                }
            })(),
            FlowActionType::AgentHandoff => (function () use ($action) {
                if ($action->data['message'] ?? null) {
                    $this->emitEvent(['type' => 'flow_message', 'content' => $action->data['message']]);
                }
                $this->emitEvent(['type' => 'flow_end', 'action' => 'agent_handoff']);
            })(),
            FlowActionType::End => $this->emitEvent([
                'type' => 'flow_end',
                'action' => $action->data['action'] ?? 'resume_conversation',
            ]),
            FlowActionType::AwaitLlmClassification => $this->emitEvent([
                'type' => 'flow_await_input',
                'input_type' => 'text',
            ]),
        };
    }

    private function emitEvent(array $data): void
    {
        echo 'data: '.json_encode($data)."\n\n";
        $this->flushOutput();
    }

    private function streamHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
