<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\AgentTeam;
use App\Models\Conversation;
use App\Models\Message;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the flow between agents in an Agent Team.
 *
 * Flow:
 * 1. User message arrives
 * 2. Triage Agent classifies intent and decides routing
 * 3. Route to Knowledge Agent (RAG), Action Agent (Tools), or respond directly
 * 4. Return the final response
 */
class TeamOrchestrationService
{
    public function __construct(
        private readonly LLMService $llmService,
        private readonly TriageRoutingService $routingService,
    ) {}

    /**
     * Orchestrate a user message through the agent team.
     *
     * Yields events as the orchestration progresses:
     * - ['type' => 'routing', 'agent' => 'triage', 'decision' => [...]]
     * - ['type' => 'agent_start', 'agent' => 'knowledge|action']
     * - ['type' => 'tool_call', 'tool' => 'tool_name']
     * - ['type' => 'knowledge_base', 'name' => 'kb_name', 'id' => 'kb_id']
     * - ['type' => 'content', 'content' => 'text chunk']
     *
     * @return Generator<array<string, mixed>>
     */
    public function orchestrate(
        AgentTeam $team,
        Conversation $conversation,
        string $userMessage
    ): Generator {
        // Load team agents
        $team->load(['triageAgent', 'knowledgeAgent', 'actionAgent']);

        // Get conversation history for context
        $messages = $conversation->messages()->get();

        Log::info('Starting team orchestration', [
            'team_id' => $team->id,
            'conversation_id' => $conversation->id,
            'message_count' => $messages->count(),
        ]);

        // Step 1: Run triage to classify the request
        $triageResult = $this->runTriage($team, $messages, $userMessage);

        yield [
            'type' => 'routing',
            'agent' => 'triage',
            'decision' => $triageResult,
        ];

        Log::info('Triage decision', [
            'team_id' => $team->id,
            'action' => $triageResult['action'],
        ]);

        // Step 2: Execute based on routing decision
        switch ($triageResult['action']) {
            case 'knowledge':
                yield ['type' => 'agent_start', 'agent' => 'knowledge'];
                yield from $this->executeKnowledge($team, $messages, $triageResult, $userMessage);
                break;

            case 'action':
                yield ['type' => 'agent_start', 'agent' => 'action'];
                yield from $this->executeAction($team, $messages, $triageResult, $userMessage);
                break;

            case 'direct':
            default:
                // Triage responded directly
                yield ['type' => 'content', 'content' => $triageResult['response'] ?? ''];
                break;
        }
    }

    /**
     * Run the Triage Agent to classify the request.
     *
     * @param  Collection<int, Message>  $messages
     * @return array{action: string, query?: string, task?: string, response?: string, urgency?: string, context?: array}
     */
    private function runTriage(AgentTeam $team, Collection $messages, string $userMessage): array
    {
        if (! $team->triageAgent) {
            // No triage agent - default to knowledge if available, otherwise direct
            if ($team->knowledgeAgent) {
                return ['action' => 'knowledge', 'query' => $userMessage, 'urgency' => 'medium'];
            }
            if ($team->actionAgent) {
                return ['action' => 'action', 'task' => $userMessage, 'context' => []];
            }

            return ['action' => 'direct', 'response' => 'No agents configured to handle your request.'];
        }

        // Build routing tools for the triage agent
        $routingTools = $this->routingService->buildRoutingTools($team);

        // Prepare messages for triage (include conversation history)
        $triageMessages = $this->prepareTriageMessages($messages, $userMessage);

        Log::info('Running triage', [
            'team_id' => $team->id,
            'triage_agent_id' => $team->triageAgent->id,
            'routing_tools' => count($routingTools),
        ]);

        // Call triage with routing tools
        $response = $this->llmService->chatWithRoutingTools(
            $team->triageAgent,
            $triageMessages,
            $routingTools
        );

        // Extract routing decision from tool call results
        return $this->extractRoutingDecision($response);
    }

    /**
     * Prepare messages for the triage agent.
     *
     * @param  Collection<int, Message>  $messages
     * @return array<Message>
     */
    private function prepareTriageMessages(Collection $messages, string $userMessage): array
    {
        // Include recent conversation history (last 10 messages for context)
        $history = $messages->take(-10)->values()->all();

        // Add the current user message
        $currentMessage = new Message([
            'role' => MessageRole::User,
            'content' => $userMessage,
        ]);

        return array_merge($history, [$currentMessage]);
    }

    /**
     * Extract routing decision from Prism response.
     *
     * When using maxSteps=1 (single LLM call), the tool is called but not executed,
     * so we extract the routing decision from the tool arguments instead of the result.
     * The tool name tells us the action, and arguments contain the details.
     *
     * @return array{action: string, query?: string, task?: string, response?: string, urgency?: string, context?: array}
     */
    private function extractRoutingDecision($response): array
    {
        // Prism stores tool calls in steps
        foreach ($response->steps ?? [] as $step) {
            foreach ($step->toolCalls ?? [] as $toolCall) {
                $toolName = $toolCall->name ?? '';
                $args = $toolCall->arguments();

                // Map tool name to action and extract arguments
                switch ($toolName) {
                    case 'route_to_knowledge':
                        return [
                            'action' => 'knowledge',
                            'query' => $args['query'] ?? '',
                            'urgency' => $args['urgency'] ?? 'medium',
                        ];

                    case 'route_to_action':
                        $context = [];
                        if (isset($args['context'])) {
                            $decoded = is_string($args['context'])
                                ? json_decode($args['context'], true)
                                : $args['context'];
                            if (is_array($decoded)) {
                                $context = $decoded;
                            }
                        }

                        return [
                            'action' => 'action',
                            'task' => $args['task'] ?? '',
                            'context' => $context,
                        ];

                    case 'respond_directly':
                        return [
                            'action' => 'direct',
                            'response' => $args['response'] ?? '',
                        ];
                }
            }
        }

        // Fallback: if no tool was called, treat response as direct
        return [
            'action' => 'direct',
            'response' => $response->text ?? 'I\'m not sure how to help with that.',
        ];
    }

    /**
     * Execute the Knowledge Agent (RAG) flow.
     *
     * @param  Collection<int, Message>  $messages
     * @return Generator<array<string, mixed>>
     */
    private function executeKnowledge(
        AgentTeam $team,
        Collection $messages,
        array $triageResult,
        string $originalMessage
    ): Generator {
        if (! $team->knowledgeAgent) {
            yield ['type' => 'content', 'content' => 'Knowledge agent is not configured.'];

            return;
        }

        // Use the refined query from triage, or fall back to original message
        $query = $triageResult['query'] ?? $originalMessage;

        // Prepare messages for knowledge agent
        $knowledgeMessages = $this->prepareAgentMessages($messages, $originalMessage);

        Log::info('Executing knowledge agent', [
            'team_id' => $team->id,
            'agent_id' => $team->knowledgeAgent->id,
            'query' => $query,
        ]);

        // Use RAG with info to get knowledge bases
        $result = $this->llmService->streamChatWithRAGInfo(
            $team->knowledgeAgent,
            $knowledgeMessages,
            $query
        );

        // Emit knowledge base events
        foreach ($result['knowledge_bases'] as $kb) {
            yield [
                'type' => 'knowledge_base',
                'name' => $kb['name'],
                'id' => $kb['id'] ?? null,
            ];
        }

        // Stream the response
        foreach ($result['generator'] as $chunk) {
            yield ['type' => 'content', 'content' => $chunk];
        }
    }

    /**
     * Execute the Action Agent (Tools) flow.
     *
     * @param  Collection<int, Message>  $messages
     * @return Generator<array<string, mixed>>
     */
    private function executeAction(
        AgentTeam $team,
        Collection $messages,
        array $triageResult,
        string $originalMessage
    ): Generator {
        if (! $team->actionAgent) {
            yield ['type' => 'content', 'content' => 'Action agent is not configured.'];

            return;
        }

        // Use the task description from triage, or fall back to original message
        $task = $triageResult['task'] ?? $originalMessage;
        $context = $triageResult['context'] ?? [];

        // Build a message that includes the task and any context
        $taskMessage = $task;
        if (! empty($context)) {
            $contextStr = json_encode($context);
            $taskMessage .= "\n\nAdditional context: {$contextStr}";
        }

        // Prepare messages for action agent
        $actionMessages = $this->prepareAgentMessages($messages, $taskMessage);

        Log::info('Executing action agent', [
            'team_id' => $team->id,
            'agent_id' => $team->actionAgent->id,
            'task' => $task,
        ]);

        // Execute with tools (non-streaming due to tool calls)
        $response = $this->llmService->chatWithTools(
            $team->actionAgent,
            $actionMessages
        );

        // Emit tool call events
        foreach ($response->steps ?? [] as $step) {
            foreach ($step->toolCalls ?? [] as $toolCall) {
                yield [
                    'type' => 'tool_call',
                    'tool' => $toolCall->name ?? 'unknown',
                ];
            }
        }

        // Yield the final response
        yield ['type' => 'content', 'content' => $response->text];
    }

    /**
     * Prepare messages for a specialized agent.
     *
     * @param  Collection<int, Message>  $messages
     * @return array<Message>
     */
    private function prepareAgentMessages(Collection $messages, string $currentMessage): array
    {
        // Include recent conversation history (last 6 messages for context)
        $history = $messages->take(-6)->values()->all();

        // Add the current message
        $current = new Message([
            'role' => MessageRole::User,
            'content' => $currentMessage,
        ]);

        return array_merge($history, [$current]);
    }
}
