<?php

namespace App\Services;

use App\Enums\BotFlowActionType;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\Message;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

/**
 * Orchestrates the flow between agents in an agent roster.
 *
 * The roster (triage / knowledge / action) is resolved from a Bot Flow's agent
 * nodes:
 * 1. User message arrives
 * 2. Triage Agent creates an execution plan (one or more steps)
 * 3. Execute each step in sequence (Knowledge, Action, or Direct)
 * 4. If multiple steps, consolidate responses into a coherent reply
 * 5. Return the final response
 */
class TeamOrchestrationService
{
    public function __construct(
        private readonly LLMService $llmService,
        private readonly TriageRoutingService $routingService,
        private readonly BotFlowExecutorService $flowExecutor,
    ) {}

    /**
     * Orchestrate a user message through a Bot Flow's agent roster.
     *
     * @param  array<int, array<string, mixed>>  $attachments  Normalized attachment descriptors for this turn.
     * @return Generator<array<string, mixed>>
     */
    public function orchestrateBotFlow(
        BotFlow $flow,
        Conversation $conversation,
        string $userMessage,
        array $attachments = []
    ): Generator {
        yield from $this->run(
            $flow->roster(),
            $flow,
            "flow:{$flow->id}",
            $conversation,
            $userMessage,
            $attachments
        );
    }

    /**
     * Run orchestration against a resolved agent roster.
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
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @return Generator<array<string, mixed>>
     */
    private function run(
        array $roster,
        ?BotFlow $flow,
        string $label,
        Conversation $conversation,
        string $userMessage,
        array $attachments = []
    ): Generator {
        // Check for active flow before LLM triage
        if ($flow !== null) {
            $flowState = $conversation->metadata['flow_state'] ?? null;

            if ($this->flowExecutor->shouldActivateBotFlow($flow, $userMessage, $flowState)) {
                yield from $this->executeFlow($roster, $flow, $conversation, $userMessage, $flowState, $attachments);

                return;
            }
        }

        // Get conversation history for context
        // Use loaded relation if available (for widget/preview), otherwise query
        $messages = $conversation->relationLoaded('messages')
            ? $conversation->messages
            : $conversation->messages()->get();

        Log::info('Starting team orchestration', [
            'orchestration' => $label,
            'conversation_id' => $conversation->id,
            'message_count' => $messages->count(),
        ]);

        // Step 1: Run triage to create execution plan
        $executionPlan = $this->createExecutionPlan($roster, $messages, $userMessage);

        yield [
            'type' => 'execution_plan',
            'steps' => $executionPlan,
        ];

        Log::info('Execution plan created', [
            'orchestration' => $label,
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
                'orchestration' => $label,
                'step' => $index,
                'agent' => $step['agent'],
            ]);

            // Execute and collect response
            $stepContent = '';

            switch ($step['agent']) {
                case 'knowledge':
                    foreach ($this->executeKnowledgeWithEvents($roster, $messages, $step, $userMessage, $attachments) as $event) {
                        if ($event['type'] === 'content') {
                            $stepContent .= $event['content'] ?? '';
                        } else {
                            // Yield non-content events (knowledge_base, etc.)
                            yield $event;
                        }
                    }
                    break;

                case 'action':
                    foreach ($this->executeActionWithEvents($roster, $messages, $step, $userMessage, $attachments) as $event) {
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
                'orchestration' => $label,
                'step_count' => count($stepResponses),
            ]);

            yield from $this->consolidateResponses($roster, $userMessage, $stepResponses);
        }
    }

    /**
     * Consolidate multiple step responses into a coherent reply.
     *
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @param  array<array{agent: string, query: ?string, response: string}>  $stepResponses
     * @return Generator<array<string, mixed>>
     */
    private function consolidateResponses(
        array $roster,
        string $userMessage,
        array $stepResponses
    ): Generator {
        if (! $roster['triage']) {
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
            'triage_agent_id' => $roster['triage']->id,
        ]);

        // Stream consolidated response
        foreach ($this->llmService->streamChat($roster['triage'], [$consolidationMessage]) as $chunk) {
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
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @param  Collection<int, Message>  $messages
     * @return array<array{agent: string, query?: string, task?: string, response?: string, urgency?: string, context?: array}>
     */
    private function createExecutionPlan(array $roster, Collection $messages, string $userMessage): array
    {
        if (! $roster['triage']) {
            // No triage agent - create simple plan based on available agents
            return $this->createFallbackPlan($roster, $userMessage);
        }

        // Build the execution plan tool
        $routingTools = $this->routingService->buildRoutingTools($roster['knowledge'], $roster['action']);

        // Prepare messages for triage (include conversation history)
        $triageMessages = $this->prepareTriageMessages($messages, $userMessage);

        Log::info('Running triage for execution plan', [
            'triage_agent_id' => $roster['triage']->id,
        ]);

        // Call triage with execution plan tool
        $response = $this->llmService->chatWithRoutingTools(
            $roster['triage'],
            $triageMessages,
            $routingTools
        );

        // Extract execution plan from tool call
        return $this->extractExecutionPlan($response);
    }

    /**
     * Create a fallback plan when no triage agent is configured.
     *
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @return array<array{agent: string, query?: string, task?: string, response?: string}>
     */
    private function createFallbackPlan(array $roster, string $userMessage): array
    {
        if ($roster['knowledge']) {
            return [['agent' => 'knowledge', 'query' => $userMessage, 'urgency' => 'medium']];
        }

        if ($roster['action']) {
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
     * Extract execution plan from the agent response.
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
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @param  Collection<int, Message>  $messages
     * @return Generator<array<string, mixed>>
     */
    private function executeKnowledgeWithEvents(
        array $roster,
        Collection $messages,
        array $step,
        string $originalMessage,
        array $attachments = []
    ): Generator {
        if (! $roster['knowledge']) {
            yield ['type' => 'content', 'content' => 'Knowledge agent is not configured.'];

            return;
        }

        // Use the refined query from the step
        $query = $step['query'] ?? $originalMessage;

        // Prepare messages for knowledge agent (document attachments are folded
        // into the message; images go to the model as stored files).
        $knowledgeMessages = $this->prepareAgentMessages($messages, $originalMessage, $attachments);

        Log::info('Executing knowledge agent', [
            'agent_id' => $roster['knowledge']->id,
            'query' => $query,
        ]);

        // Use RAG with info to get knowledge bases
        $result = $this->llmService->streamChatWithRAGInfo(
            $roster['knowledge'],
            $knowledgeMessages,
            $query,
            $this->imageAttachments($attachments)
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
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @param  Collection<int, Message>  $messages
     * @return Generator<array<string, mixed>>
     */
    private function executeActionWithEvents(
        array $roster,
        Collection $messages,
        array $step,
        string $originalMessage,
        array $attachments = []
    ): Generator {
        if (! $roster['action']) {
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
        $actionMessages = $this->prepareAgentMessages($messages, $taskMessage, $attachments);

        Log::info('Executing action agent', [
            'agent_id' => $roster['action']->id,
            'task' => $task,
        ]);

        // Execute with tools (non-streaming due to tool calls)
        $response = $this->llmService->chatWithTools(
            $roster['action'],
            $actionMessages,
            attachments: $this->imageAttachments($attachments)
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
    private function prepareAgentMessages(Collection $messages, string $currentMessage, array $attachments = []): array
    {
        // Include recent conversation history (last 6 messages for context)
        $history = $messages->take(-6)->values()->all();

        // Fold any document attachments' extracted text into the current message
        // so the agent can read their content. Images are passed to the model
        // separately as stored files (see imageAttachments()).
        $content = $currentMessage;
        $documentContext = app(ConversationAttachmentService::class)->documentContext($attachments);
        if ($documentContext !== '') {
            $content .= "\n\n".$documentContext;
        }

        $current = new Message([
            'role' => MessageRole::User,
            'content' => $content,
        ]);

        return array_merge($history, [$current]);
    }

    /**
     * Convert image attachment descriptors into Laravel AI stored files for the
     * model (vision). Non-image attachments are surfaced via documentContext().
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, StoredImage|StoredDocument|StoredAudio>
     */
    private function imageAttachments(array $attachments): array
    {
        $service = app(ConversationAttachmentService::class);
        $stored = [];
        foreach ($attachments as $descriptor) {
            // A resolvable disk is required to hand the file to the SDK.
            if (($descriptor['kind'] ?? null) === 'image' && ! empty($descriptor['disk'])) {
                $stored[] = $service->toStoredFile($descriptor);
            }
        }

        return $stored;
    }

    /**
     * Execute a Bot Flow turn against the roster.
     *
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @return Generator<array<string, mixed>>
     */
    private function executeFlow(
        array $roster,
        BotFlow $flow,
        Conversation $conversation,
        string $userMessage,
        ?array $flowState,
        array $attachments = []
    ): Generator {
        // Initialize or continue flow
        if ($flowState === null || ($flowState['completed'] ?? false)) {
            $flowState = $this->flowExecutor->initializeFlow($flow);

            yield [
                'type' => 'flow_start',
                'flow_id' => $flow->id,
                'flow_name' => $flow->name,
            ];
        }

        $action = $this->flowExecutor->processInput($flow, $flowState, $userMessage, $attachments);

        // Persist updated state
        $metadata = $conversation->metadata ?? [];
        $metadata['flow_state'] = $action->updatedState;
        $conversation->update(['metadata' => $metadata]);

        yield from $this->emitFlowAction($roster, $flow, $conversation, $action, $attachments);
    }

    /**
     * Convert a BotFlowAction into SSE events.
     *
     * @param  array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}  $roster
     * @return Generator<array<string, mixed>>
     */
    private function emitFlowAction(
        array $roster,
        BotFlow $flow,
        Conversation $conversation,
        BotFlowAction $action,
        array $attachments = []
    ): Generator {
        match ($action->type) {
            BotFlowActionType::ShowMenu => yield [
                'type' => 'flow_menu',
                'message' => $action->data['message'] ?? '',
                'options' => $action->data['options'] ?? [],
            ],
            BotFlowActionType::SendMessage => yield from (function () use ($action, $roster, $flow, $conversation, $attachments) {
                yield ['type' => 'flow_message', 'content' => $action->data['message'] ?? ''];

                // If there's a next node, auto-advance
                if (isset($action->data['next_node_id'])) {
                    $nextAction = $this->flowExecutor->advanceToNode($flow, $action->updatedState, $action->data['next_node_id']);
                    $metadata = $conversation->metadata ?? [];
                    $metadata['flow_state'] = $nextAction->updatedState;
                    $conversation->update(['metadata' => $metadata]);
                    yield from $this->emitFlowAction($roster, $flow, $conversation, $nextAction, $attachments);
                }
            })(),
            BotFlowActionType::AgentHandoff => yield from (function () use ($action, $roster, $conversation, $attachments) {
                if ($action->data['message'] ?? null) {
                    yield ['type' => 'flow_message', 'content' => $action->data['message']];
                }
                yield ['type' => 'flow_end', 'action' => 'agent_handoff'];

                // Execute the handoff by running the appropriate agent
                $messages = $conversation->relationLoaded('messages')
                    ? $conversation->messages
                    : $conversation->messages()->get();

                $targetAgent = $action->data['target_agent'] ?? 'triage_llm';
                $context = $action->data['context'] ?? null;

                $step = match ($targetAgent) {
                    'knowledge' => ['agent' => 'knowledge', 'query' => $context ?? $messages->last()?->content ?? ''],
                    'action' => ['agent' => 'action', 'task' => $context ?? $messages->last()?->content ?? '', 'context' => []],
                    default => null,
                };

                if ($step) {
                    $lastMessage = $messages->last()?->content ?? '';
                    match ($step['agent']) {
                        'knowledge' => yield from $this->executeKnowledgeWithEvents($roster, $messages, $step, $lastMessage, $attachments),
                        'action' => yield from $this->executeActionWithEvents($roster, $messages, $step, $lastMessage, $attachments),
                    };
                }
            })(),
            BotFlowActionType::CollectInput => yield from (function () use ($action) {
                if (($action->data['prompt'] ?? '') !== '') {
                    yield ['type' => 'flow_message', 'content' => $action->data['prompt']];
                }
                yield [
                    'type' => 'flow_await_input',
                    'input_type' => $action->data['input_type'] ?? 'text',
                    'variable' => $action->data['variable'] ?? 'input',
                ];
            })(),
            BotFlowActionType::HumanHandoff => yield from (function () use ($action) {
                if ($action->data['message'] ?? null) {
                    yield ['type' => 'flow_message', 'content' => $action->data['message']];
                }
                yield [
                    'type' => 'flow_human_handoff',
                    'reason' => $action->data['reason'] ?? null,
                    'notify' => $action->data['notify'] ?? true,
                ];
                yield ['type' => 'flow_end', 'action' => 'human_handoff'];
            })(),
            BotFlowActionType::End => yield [
                'type' => 'flow_end',
                'action' => $action->data['action'] ?? 'resume_conversation',
            ],
            BotFlowActionType::AwaitLlmClassification => yield [
                'type' => 'flow_await_input',
                'input_type' => 'text',
            ],
        };
    }
}
