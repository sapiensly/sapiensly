<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Models\AgentTeam;
use App\Models\Conversation;
use App\Services\TeamOrchestrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamStreamController extends Controller
{
    public function __construct(
        private TeamOrchestrationService $orchestrationService
    ) {}

    public function stream(Request $request, AgentTeam $agentTeam, Conversation $conversation): StreamedResponse
    {
        // Verify ownership
        if ($agentTeam->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        // Get the last user message
        $lastMessage = $conversation->messages()
            ->where('role', MessageRole::User)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $lastMessage) {
            return response()->stream(function () {
                echo 'data: '.json_encode(['error' => 'No user message found'])."\n\n";
                $this->flushOutput();
            }, 200, $this->streamHeaders());
        }

        // Collect all events from orchestration
        $events = [];
        $fullContent = '';
        $metadata = [
            'routing' => null,
            'tool_calls' => [],
            'knowledge_bases' => [],
        ];
        $error = null;

        try {
            Log::info('Starting team orchestration stream', [
                'team_id' => $agentTeam->id,
                'conversation_id' => $conversation->id,
            ]);

            foreach ($this->orchestrationService->orchestrate($agentTeam, $conversation, $lastMessage->content) as $event) {
                $events[] = $event;

                // Track metadata for saving
                match ($event['type'] ?? null) {
                    'execution_plan' => $metadata['execution_plan'] = $event['steps'] ?? [],
                    'step_start' => $metadata['current_step'] = $event['step'] ?? 0,
                    'tool_call' => $metadata['tool_calls'][] = ['name' => $event['tool'] ?? 'unknown'],
                    'knowledge_base' => $metadata['knowledge_bases'][] = [
                        'name' => $event['name'] ?? 'unknown',
                        'id' => $event['id'] ?? null,
                    ],
                    'content' => $fullContent .= $event['content'] ?? '',
                    default => null,
                };
            }

            Log::info('Team orchestration completed', [
                'team_id' => $agentTeam->id,
                'event_count' => count($events),
                'content_length' => strlen($fullContent),
            ]);
        } catch (\Exception $e) {
            Log::error('Team Orchestration Error', [
                'team_id' => $agentTeam->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $error = $e->getMessage();
        }

        return response()->stream(function () use ($agentTeam, $conversation, $events, $fullContent, $metadata, $error) {
            // Stream all collected events
            foreach ($events as $event) {
                echo 'data: '.json_encode($event)."\n\n";
                $this->flushOutput();
            }

            if ($error) {
                echo 'data: '.json_encode(['error' => $error])."\n\n";
                $this->flushOutput();

                return;
            }

            // Save the assistant message with full metadata
            if ($fullContent !== '') {
                $messageMetadata = [];
                if (! empty($metadata['execution_plan'])) {
                    $messageMetadata['execution_plan'] = $metadata['execution_plan'];
                }
                if (! empty($metadata['tool_calls'])) {
                    $messageMetadata['tool_calls'] = $metadata['tool_calls'];
                }
                if (! empty($metadata['knowledge_bases'])) {
                    $messageMetadata['knowledge_bases'] = $metadata['knowledge_bases'];
                }

                $conversation->messages()->create([
                    'role' => MessageRole::Assistant,
                    'content' => $fullContent,
                    'model' => $this->getRespondingAgentModel($agentTeam, $metadata['execution_plan']),
                    'metadata' => ! empty($messageMetadata) ? $messageMetadata : null,
                ]);
            }

            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, $this->streamHeaders());
    }

    /**
     * Get the model of the primary responding agent based on execution plan.
     *
     * Returns the model of the first non-direct agent in the plan,
     * or the triage agent's model if all steps are direct.
     */
    private function getRespondingAgentModel(AgentTeam $team, array $executionPlan): ?string
    {
        foreach ($executionPlan as $step) {
            $agent = $step['agent'] ?? 'direct';

            return match ($agent) {
                'knowledge' => $team->knowledgeAgent?->model,
                'action' => $team->actionAgent?->model,
                default => $team->triageAgent?->model,
            };
        }

        return $team->triageAgent?->model;
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
