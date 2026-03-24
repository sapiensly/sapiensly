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
 * 2. Triage Agent creates an execution plan (one or more steps)
 * 3. Execute each step in sequence (Knowledge, Action, or Direct)
 * 4. If multiple steps, consolidate responses into coherent reply
 * 5. Return the final response
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
     * - ['type' => 'execution_plan', 'steps' => [...]]
     * - ['type' => 'step_start', 'step' => int, 'agent' => 'knowledge|action|direct', 'details' => [...]]
     * - ['type' => 'tool_call', 'tool' => 'tool_name']
     * - ['type' => 'knowledge_base', 'name' => 'kb_name', 'id' => 'kb_id']
     * - ['type' => 'step_complete', 'step' => int, 'response' => string]
     * - ['type' => 'consolidating'] (when multiple steps need consolidation)
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
        // Use loaded relation if available (for widget/preview), otherwise query
        $messages = $conversation->relationLoaded('messages')
            ? $conversation->messages
            : $conversation->messages()->get();

        Log::info('Starting team orchestration', [
            'team_id' => $team->id,
            'conversation_id' => $conversation->id,
            'message_count' => $messages->count(),
        ]);

        // Step 1: Run triage to create execution plan
        $executionPlan = $this->createExecutionPlan($team, $messages, $userMessage);

        yield [
            'type' => 'execution_plan',
            'steps' => $executionPlan,
        ];

        Log::info('Execution plan created', [
            'team_id' => $team->id,
            'step_count' => count($executionPlan),
            'steps' => $executionPlan,
        ]);

        // Step 2: Execute each step and collect responses
        $stepResponses = [];

        foreach ($executionPlan as $index => $step) {
            yield [
                'type' => 'step_start',
                'step' => $index,
                'agent' => $step['agent'],
                'details' => $step,
            ];

            Log::info('Executing step', [
                'team_id' => $team->id,
                'step' => $index,
                'agent' => $step['agent'],
            ]);

            // Execute and collect response
            $stepContent = '';

            switch ($step['agent']) {
                case 'knowledge':
                    foreach ($this->executeKnowledgeWithEvents($team, $messages, $step, $userMessage) as $event) {
                        if ($event['type'] === 'content') {
                            $stepContent .= $event['content'] ?? '';
                        } else {
                            // Yield non-content events (knowledge_base, etc.)
                            yield $event;
                        }
                    }
                    break;

                case 'action':
                    foreach ($this->executeActionWithEvents($team, $messages, $step, $userMessage) as $event) {
                        if ($event['type'] === 'content') {
                            $stepContent .= $event['content'] ?? '';
                        } else {
                            // Yield non-content events (tool_call, etc.)
                            yield $event;
                        }
                    }
                    break;

                case 'direct':
                default:
                    $stepContent = $step['response'] ?? '';
                    break;
            }

            $stepResponses[] = [
                'agent' => $step['agent'],
                'query' => $step['query'] ?? $step['task'] ?? null,
                'response' => $stepContent,
            ];

            yield [
                'type' => 'step_complete',
                'step' => $index,
                'response' => $stepContent,
            ];
        }

        // Step 3: Consolidate or return directly
        if (count($stepResponses) === 1) {
            // Single step - return response directly
            yield ['type' => 'content', 'content' => $stepResponses[0]['response']];
        } else {
            // Multiple steps - consolidate responses
            yield ['type' => 'consolidating'];

            Log::info('Consolidating responses', [
                'team_id' => $team->id,
                'step_count' => count($stepResponses),
            ]);

            yield from $this->consolidateResponses($team, $userMessage, $stepResponses);
        }
    }

    /**
     * Consolidate multiple step responses into a coherent reply.
     *
     * @param  array<array{agent: string, query: ?string, response: string}>  $stepResponses
     * @return Generator<array<string, mixed>>
     */
    private function consolidateResponses(
        AgentTeam $team,
        string $userMessage,
        array $stepResponses
    ): Generator {
        if (! $team->triageAgent) {
            // No triage agent - just concatenate with separator
            foreach ($stepResponses as $i => $step) {
                if ($i > 0) {
                    yield ['type' => 'content', 'content' => "\n\n---\n\n"];
                }
                yield ['type' => 'content', 'content' => $step['response']];
            }

            return;
        }

        // Build consolidation prompt
        $consolidationPrompt = $this->buildConsolidationPrompt($userMessage, $stepResponses);

        // Create a message for consolidation
        $consolidationMessage = new Message([
            'role' => MessageRole::User,
            'content' => $consolidationPrompt,
        ]);

        Log::info('Running consolidation', [
            'team_id' => $team->id,
            'triage_agent_id' => $team->triageAgent->id,
        ]);

        // Stream consolidated response
        foreach ($this->llmService->streamChat($team->triageAgent, [$consolidationMessage]) as $chunk) {
            yield ['type' => 'content', 'content' => $chunk];
        }
    }

    /**
     * Build the consolidation prompt for the Triage Agent.
     *
     * @param  array<array{agent: string, query: ?string, response: string}>  $stepResponses
     */
    private function buildConsolidationPrompt(string $userMessage, array $stepResponses): string
    {
        $responsesText = '';

        foreach ($stepResponses as $i => $step) {
            $agentLabel = match ($step['agent']) {
                'knowledge' => 'Knowledge Base',
                'action' => 'System Action',
                'direct' => 'Direct Response',
                default => 'Agent',
            };

            $query = $step['query'] ? " (regarding: {$step['query']})" : '';
            $responsesText .= "### Response from {$agentLabel}{$query}\n{$step['response']}\n\n";
        }

        return <<<PROMPT
The user asked: "{$userMessage}"

I gathered the following information from different sources:

{$responsesText}

Please consolidate these responses into a single, coherent reply for the user. Guidelines:
- Combine the information naturally without repetition
- Remove duplicate greetings or closings
- Maintain a consistent tone throughout
- Present the information in a logical order
- If there are contradictions, note them clearly
- Keep the response concise but complete

Provide only the consolidated response, nothing else.
PROMPT;
    }

    /**
     * Create an execution plan using the Triage Agent.
     *
     * @param  Collection<int, Message>  $messages
     * @return array<array{agent: string, query?: string, task?: string, response?: string, urgency?: string, context?: array}>
     */
    private function createExecutionPlan(AgentTeam $team, Collection $messages, string $userMessage): array
    {
        if (! $team->triageAgent) {
            // No triage agent - create simple plan based on available agents
            return $this->createFallbackPlan($team, $userMessage);
        }

        // Build the execution plan tool
        $routingTools = $this->routingService->buildRoutingTools($team);

        // Prepare messages for triage (include conversation history)
        $triageMessages = $this->prepareTriageMessages($messages, $userMessage);

        Log::info('Running triage for execution plan', [
            'team_id' => $team->id,
            'triage_agent_id' => $team->triageAgent->id,
        ]);

        // Call triage with execution plan tool
        $response = $this->llmService->chatWithRoutingTools(
            $team->triageAgent,
            $triageMessages,
            $routingTools
        );

        // Extract execution plan from tool call
        return $this->extractExecutionPlan($response);
    }

    /**
     * Create a fallback plan when no triage agent is configured.
     *
     * @return array<array{agent: string, query?: string, task?: string, response?: string}>
     */
    private function createFallbackPlan(AgentTeam $team, string $userMessage): array
    {
        if ($team->knowledgeAgent) {
            return [['agent' => 'knowledge', 'query' => $userMessage, 'urgency' => 'medium']];
        }

        if ($team->actionAgent) {
            return [['agent' => 'action', 'task' => $userMessage, 'context' => []]];
        }

        return [['agent' => 'direct', 'response' => 'No agents configured to handle your request.']];
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
     * Extract execution plan from Prism response.
     *
     * @return array<array{agent: string, query?: string, task?: string, response?: string, urgency?: string, context?: array}>
     */
    private function extractExecutionPlan($response): array
    {
        // Look for the create_execution_plan tool call
        foreach ($response->steps ?? [] as $step) {
            foreach ($step->toolCalls ?? [] as $toolCall) {
                if (($toolCall->name ?? '') === 'create_execution_plan') {
                    $args = $toolCall->arguments;
                    $stepsJson = $args['steps'] ?? '[]';

                    return $this->routingService->parseExecutionPlan($stepsJson);
                }
            }
        }

        // Fallback: if no tool was called, treat response as direct
        $responseText = $response->text ?? 'I\'m not sure how to help with that.';

        return [['agent' => 'direct', 'response' => $responseText]];
    }

    /**
     * Execute the Knowledge Agent (RAG) flow, yielding events.
     *
     * @param  Collection<int, Message>  $messages
     * @return Generator<array<string, mixed>>
     */
    private function executeKnowledgeWithEvents(
        AgentTeam $team,
        Collection $messages,
        array $step,
        string $originalMessage
    ): Generator {
        if (! $team->knowledgeAgent) {
            yield ['type' => 'content', 'content' => 'Knowledge agent is not configured.'];

            return;
        }

        // Use the refined query from the step
        $query = $step['query'] ?? $originalMessage;

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
     * Execute the Action Agent (Tools) flow, yielding events.
     *
     * @param  Collection<int, Message>  $messages
     * @return Generator<array<string, mixed>>
     */
    private function executeActionWithEvents(
        AgentTeam $team,
        Collection $messages,
        array $step,
        string $originalMessage
    ): Generator {
        if (! $team->actionAgent) {
            yield ['type' => 'content', 'content' => 'Action agent is not configured.'];

            return;
        }

        // Use the task description from the step
        $task = $step['task'] ?? $originalMessage;
        $context = $step['context'] ?? [];

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
        foreach ($response->steps ?? [] as $responseStep) {
            foreach ($responseStep->toolCalls ?? [] as $toolCall) {
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
