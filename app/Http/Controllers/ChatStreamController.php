<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Services\LLMService;
use Illuminate\Http\Request;
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

        // Collect chunks from LLM
        $chunks = [];
        $error = null;

        try {
            foreach ($this->llmService->streamChat($agent, $messages->all()) as $chunk) {
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

        return response()->stream(function () use ($agent, $conversation, $chunks, $error) {
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
