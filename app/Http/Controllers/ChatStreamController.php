<?php

namespace App\Http\Controllers;

use App\Enums\AgentType;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Services\LLMService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController extends Controller
{
    public function __construct(
        private LLMService $llmService
    ) {}

    public function stream(Request $request, Agent $agent, Conversation $conversation): StreamedResponse
    {
        // Verify ownership
        if ($agent->user_id !== $request->user()->id) {
            abort(403);
        }

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
     * Tool calling uses non-streaming mode because Prism executes
     * tools synchronously. The final response is then streamed to the client.
     */
    private function streamWithTools(Agent $agent, Conversation $conversation, Collection $messages): StreamedResponse
    {
        $responseText = null;
        $toolCalls = [];
        $error = null;

        try {
            \Log::info('Starting tool-enabled chat', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
            ]);

            $response = $this->llmService->chatWithTools($agent, $messages->all());
            $responseText = $response->text;

            // Extract tool call information from steps for logging
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

            \Log::info('Tool chat completed', [
                'agent_id' => $agent->id,
                'tool_calls' => $toolCalls,
                'response_length' => strlen($responseText ?? ''),
            ]);
        } catch (\Exception $e) {
            \Log::error('Tool Chat Error', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $error = $e->getMessage();
        }

        return response()->stream(function () use ($agent, $conversation, $responseText, $toolCalls, $error) {
            // Send tool call events first (for UI feedback)
            foreach ($toolCalls as $toolCall) {
                echo 'data: '.json_encode([
                    'type' => 'tool_call',
                    'tool' => $toolCall['name'],
                ])."\n\n";
                $this->flushOutput();
            }

            if ($error) {
                echo 'data: '.json_encode(['error' => $error])."\n\n";
                $this->flushOutput();

                return;
            }

            if ($responseText !== null && $responseText !== '') {
                // Stream the response as a single chunk (it's already complete)
                echo 'data: '.json_encode(['content' => $responseText])."\n\n";
                $this->flushOutput();

                // Save the complete assistant message
                $conversation->messages()->create([
                    'role' => MessageRole::Assistant,
                    'content' => $responseText,
                    'model' => $agent->model,
                    'metadata' => ! empty($toolCalls) ? ['tool_calls' => $toolCalls] : null,
                ]);
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
        $chunks = [];
        $knowledgeBases = [];
        $error = null;

        try {
            // Use RAG-enabled streaming with metadata
            $ragResult = $this->llmService->streamChatWithRAGInfo($agent, $messages->all());
            $knowledgeBases = $ragResult['knowledge_bases'];

            foreach ($ragResult['generator'] as $chunk) {
                $chunks[] = $chunk;
            }
        } catch (\Exception $e) {
            \Log::error('LLM Stream Error', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'message_count' => $messages->count(),
                'error' => $e->getMessage(),
            ]);
            $error = $e->getMessage();
        }

        return response()->stream(function () use ($agent, $conversation, $chunks, $knowledgeBases, $error) {
            // Send knowledge base events first (for UI feedback)
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

                return;
            }

            $fullContent = '';
            foreach ($chunks as $chunk) {
                $fullContent .= $chunk;
                echo 'data: '.json_encode(['content' => $chunk])."\n\n";
                $this->flushOutput();
            }

            // Save the complete assistant message
            if ($fullContent !== '') {
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
