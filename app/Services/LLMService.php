<?php

namespace App\Services;

use App\Ai\RuntimeAgent;
use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Support\CurrentDateTime;
use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\TextDelta;

class LLMService
{
    private ?RetrievalService $retrievalService = null;

    private ?ToolBuilderService $toolBuilderService = null;

    private ?AiProviderService $aiProviderService = null;

    private ?User $contextUser = null;

    /**
     * Set the user context explicitly (for queue jobs).
     */
    public function setContext(?User $user): self
    {
        $this->contextUser = $user;

        if ($user) {
            $this->getAiProviderService()->applyRuntimeConfig($user);
        }

        return $this;
    }

    /**
     * Record this agent call's token usage + cost for org-level spend tracking.
     * Best-effort (the recorder swallows its own errors).
     */
    private function recordUsage(Agent $agent, string $module, ?Usage $usage): void
    {
        $user = $this->contextUser ?? $agent->user;
        app(AiUsageRecorder::class)->record(
            $module,
            $agent->model,
            $user,
            $user?->organization_id ?? $agent->organization_id,
            $usage,
        );
    }

    /**
     * Get the provider for a given model, resolved from DB-configured providers.
     */
    public function getProvider(string $model, ?Agent $agent = null): Lab
    {
        $user = $this->resolveUser($agent);

        // Budget gate: every LLMService call routes through here right before the
        // SDK call, so this single check hard-blocks an over-budget org.
        app(AiSpendGuard::class)->assertWithinBudget($user, $user?->organization_id ?? $agent?->organization_id, $model);

        if ($user) {
            // Resolve by the model's own catalog driver first (broker-aware, e.g.
            // OpenRouter ids like `deepseek/deepseek-v4-pro`), matching the UI chat
            // path; fall back to the tenant's configured providers. Without this,
            // a brokered model is misrouted to the Anthropic fallback and 404s.
            return $this->getAiProviderService()->resolveProviderForCatalogModel($model, $user)
                ?? $this->getAiProviderService()->resolveProvider($model, $user);
        }

        return Lab::Anthropic;
    }

    /**
     * Total HTTP timeout (seconds) for the SDK's blocking prompt() calls. The
     * SDK defaults to 60s, which kills legitimately slow reasoning models before
     * they finish; this raises it to the configured bound (still below the AI
     * worker timeout). Streaming calls are unaffected — they rely on the idle
     * watchdog, not a total cap.
     */
    private function requestTimeout(): int
    {
        return (int) config('ai.request_timeout', 180);
    }

    /**
     * Resolve the user from context or agent relationship.
     */
    private function resolveUser(?Agent $agent = null): ?User
    {
        if ($this->contextUser) {
            return $this->contextUser;
        }

        if ($agent) {
            return $agent->user;
        }

        return auth()->user();
    }

    /**
     * Send a chat message and get a response (non-streaming).
     *
     * @param  array<Message>  $messages
     * @param  array<int, StoredImage|StoredDocument|StoredAudio>  $attachments
     */
    public function chat(Agent $agent, array $messages, array $attachments = []): string
    {
        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history);

        $response = $sdkAgent->prompt(
            $prompt,
            attachments: $attachments,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
            timeout: $this->requestTimeout(),
        );

        $this->recordUsage($agent, 'agent', $response->usage ?? null);

        return $response->text;
    }

    /**
     * Run an agent turn through the STREAMING transport but return the full text,
     * so a blocking caller (e.g. one agent consulting another mid-turn) inherits
     * the SSE idle watchdog instead of the SDK's short blocking request timeout.
     * A slow reasoning model then survives as long as tokens keep flowing.
     *
     * When $onDelta is given it is invoked with each text chunk as it streams, so
     * the caller can relay live progress (e.g. into a consultation card) while
     * still receiving the assembled reply as the return value.
     *
     * @param  array<Message>  $messages
     * @param  array<int, StoredImage|StoredDocument|StoredAudio>  $attachments
     * @param  (callable(string): void)|null  $onDelta
     */
    public function chatStreamed(Agent $agent, array $messages, array $attachments = [], ?callable $onDelta = null): string
    {
        $text = '';
        foreach ($this->streamChat($agent, $messages, $attachments) as $delta) {
            $text .= $delta;
            if ($onDelta !== null) {
                $onDelta($delta);
            }
        }

        return $text;
    }

    /**
     * Stream a chat response.
     *
     * @param  array<Message>  $messages
     * @param  array<int, StoredImage|StoredDocument|StoredAudio>  $attachments
     * @return Generator<string>
     */
    public function streamChat(Agent $agent, array $messages, array $attachments = []): Generator
    {
        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history);

        $response = $sdkAgent->stream(
            $prompt,
            attachments: $attachments,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
        );

        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                yield $event->delta;
            }
        }

        $this->recordUsage($agent, 'agent', $response->usage ?? null);
    }

    /**
     * Stream a chat response with RAG (Retrieval Augmented Generation).
     *
     * @param  array<Message>  $messages
     * @param  string|null  $userQuery  The query to use for retrieval (defaults to last user message)
     * @return Generator<string>
     */
    public function streamChatWithRAG(Agent $agent, array $messages, ?string $userQuery = null): Generator
    {
        $result = $this->streamChatWithRAGInfo($agent, $messages, $userQuery);
        yield from $result['generator'];
    }

    /**
     * Stream a chat response with RAG and return retrieval metadata.
     *
     * @param  array<Message>  $messages
     * @param  string|null  $userQuery  The query to use for retrieval (defaults to last user message)
     * @param  array<int, StoredImage|StoredDocument|StoredAudio>  $attachments
     * @return array{generator: Generator<string>, knowledge_bases: array<array{id: string, name: string}>, chunk_count: int}
     */
    public function streamChatWithRAGInfo(Agent $agent, array $messages, ?string $userQuery = null, array $attachments = []): array
    {
        $knowledgeBaseIds = $agent->knowledgeBaseIds();

        if (empty($knowledgeBaseIds)) {
            return [
                'generator' => $this->streamChat($agent, $messages, $attachments),
                'knowledge_bases' => [],
                'chunk_count' => 0,
            ];
        }

        if ($userQuery === null) {
            $lastUserMessage = collect($messages)
                ->filter(fn ($m) => ($m->role instanceof MessageRole ? $m->role : MessageRole::from($m->role)) === MessageRole::User)
                ->last();

            $userQuery = $lastUserMessage?->content ?? '';
        }

        if (empty(trim($userQuery))) {
            return [
                'generator' => $this->streamChat($agent, $messages, $attachments),
                'knowledge_bases' => [],
                'chunk_count' => 0,
            ];
        }

        $retrieval = $this->getRetrievalService()->retrieve(
            $userQuery,
            $knowledgeBaseIds,
            topK: 5,
            threshold: 0.5
        );

        Log::info('RAG retrieval completed', [
            'agent_id' => $agent->id,
            'query_length' => strlen($userQuery),
            'chunks_found' => $retrieval['chunk_count'],
            'knowledge_bases' => $retrieval['knowledge_bases'],
        ]);

        if (empty($retrieval['context'])) {
            return [
                'generator' => $this->streamChat($agent, $messages, $attachments),
                'knowledge_bases' => [],
                'chunk_count' => 0,
            ];
        }

        $augmentedSystemPrompt = $this->buildAugmentedSystemPrompt(
            $agent->prompt_template ?? '',
            $retrieval['context']
        );

        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history, systemPrompt: $augmentedSystemPrompt);

        $generator = (function () use ($sdkAgent, $prompt, $agent, $attachments) {
            $response = $sdkAgent->stream(
                $prompt,
                attachments: $attachments,
                provider: $this->getProvider($agent->model, $agent),
                model: $agent->model,
            );

            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    yield $event->delta;
                }
            }

            $this->recordUsage($agent, 'agent', $response->usage ?? null);
        })();

        return [
            'generator' => $generator,
            'knowledge_bases' => $retrieval['knowledge_bases'],
            'chunk_count' => $retrieval['chunk_count'],
        ];
    }

    /**
     * Chat with tool calling support for Action Agents.
     *
     * @param  array<Message>  $messages
     */
    public function chatWithTools(Agent $agent, array $messages, int $maxSteps = 5, array $attachments = []): AgentResponse
    {
        $tools = $agent->tools()->where('status', 'active')->get();

        $sdkTools = $this->getToolBuilderService()->buildTools($tools);

        Log::info('Building chat with tools', [
            'agent_id' => $agent->id,
            'tool_count' => count($sdkTools),
            'tool_names' => collect($sdkTools)->map(fn ($t) => $t->name())->all(),
            'max_steps' => $maxSteps,
        ]);

        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history, $sdkTools);

        $response = $sdkAgent->prompt(
            $prompt,
            attachments: $attachments,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
            timeout: $this->requestTimeout(),
        );

        $lastStep = $response->steps->last();

        Log::info('Tool chat completed', [
            'agent_id' => $agent->id,
            'steps' => $response->steps->count(),
            'finish_reason' => $lastStep?->finishReason?->name ?? 'unknown',
        ]);

        $this->recordUsage($agent, 'agent', $response->usage ?? null);

        return $response;
    }

    /**
     * Unified chat for General Agents: injects knowledge-base RAG context into
     * the system prompt AND exposes the agent's tools in the same call, so a
     * single agent can triage, answer from knowledge, and act with tools. The
     * model itself decides whether to answer from the retrieved context or call
     * a tool.
     *
     * @param  array<Message>  $messages
     * @return array{response: AgentResponse, knowledge_bases: array<array{id: string, name: string}>, chunk_count: int}
     */
    public function chatWithKnowledgeAndTools(Agent $agent, array $messages, int $maxSteps = 5): array
    {
        $knowledgeBases = [];
        $chunkCount = 0;
        $systemPrompt = $agent->prompt_template ?? '';

        $knowledgeBaseIds = $agent->knowledgeBaseIds();
        $userQuery = $this->lastUserMessageContent($messages);

        if (! empty($knowledgeBaseIds) && trim($userQuery) !== '') {
            $ragParams = $agent->config['rag_params'] ?? [];
            $retrieval = $this->getRetrievalService()->retrieve(
                $userQuery,
                $knowledgeBaseIds,
                topK: (int) ($ragParams['top_k'] ?? 5),
                threshold: (float) ($ragParams['similarity_threshold'] ?? 0.5),
            );

            if (! empty($retrieval['context'])) {
                $systemPrompt = $this->buildAugmentedSystemPrompt($systemPrompt, $retrieval['context']);
                $knowledgeBases = $retrieval['knowledge_bases'];
                $chunkCount = $retrieval['chunk_count'];
            }
        }

        $sdkTools = $this->getToolBuilderService()->buildTools(
            $agent->tools()->where('status', 'active')->get()
        );

        Log::info('Building general chat (knowledge + tools)', [
            'agent_id' => $agent->id,
            'knowledge_base_count' => count($knowledgeBases),
            'tool_count' => count($sdkTools),
        ]);

        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history, $sdkTools, $systemPrompt);

        $response = $sdkAgent->prompt(
            $prompt,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
            timeout: $this->requestTimeout(),
        );

        $this->recordUsage($agent, 'agent', $response->usage ?? null);

        return [
            'response' => $response,
            'knowledge_bases' => $knowledgeBases,
            'chunk_count' => $chunkCount,
        ];
    }

    /**
     * Chat with custom routing tools (for Triage Agents).
     *
     * @param  array<Message>  $messages
     * @param  array<Tool>  $tools
     */
    public function chatWithRoutingTools(Agent $agent, array $messages, array $tools, int $maxSteps = 1): AgentResponse
    {
        Log::info('Building chat with routing tools', [
            'agent_id' => $agent->id,
            'tool_count' => count($tools),
            'tool_names' => collect($tools)->map(fn ($t) => $t->name())->all(),
        ]);

        [$history, $prompt] = $this->splitMessages($messages);
        // Routing/triage agents only get their handoff tools — no platform tools.
        $sdkAgent = $this->buildAgent($agent, $history, $tools, platformTools: false);

        $response = $sdkAgent->prompt(
            $prompt,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
            timeout: $this->requestTimeout(),
        );

        $lastStep = $response->steps->last();

        Log::info('Routing chat completed', [
            'agent_id' => $agent->id,
            'steps' => $response->steps->count(),
            'finish_reason' => $lastStep?->finishReason?->name ?? 'unknown',
        ]);

        $this->recordUsage($agent, 'agent', $response->usage ?? null);

        return $response;
    }

    /**
     * Get the tool builder service (lazy initialization).
     */
    private function getToolBuilderService(): ToolBuilderService
    {
        if ($this->toolBuilderService === null) {
            $this->toolBuilderService = app(ToolBuilderService::class);
        }

        return $this->toolBuilderService;
    }

    /**
     * Get the AI provider service (lazy initialization).
     */
    private function getAiProviderService(): AiProviderService
    {
        if ($this->aiProviderService === null) {
            $this->aiProviderService = app(AiProviderService::class);
        }

        return $this->aiProviderService;
    }

    /**
     * Build an augmented system prompt with retrieved context.
     */
    private function buildAugmentedSystemPrompt(string $originalPrompt, string $context): string
    {
        $augmentation = <<<EOT

---
## Relevant Context

The following information was retrieved from the knowledge base and may be relevant to answering the user's question:

{$context}

---
Use this context to inform your response when relevant. If the context doesn't contain the answer, you may say so, but try to be helpful based on what you do know.
EOT;

        return $originalPrompt.$augmentation;
    }

    /**
     * Get the retrieval service (lazy initialization).
     */
    private function getRetrievalService(): RetrievalService
    {
        if ($this->retrievalService === null) {
            $this->retrievalService = new RetrievalService;
        }

        return $this->retrievalService;
    }

    /**
     * Build the SDK agent configured for the given agent.
     *
     * @param  array<UserMessage|AssistantMessage>  $messages
     * @param  array<Tool>  $tools
     */
    private function buildAgent(Agent $agent, array $messages, array $tools = [], ?string $systemPrompt = null, bool $platformTools = true): AnonymousAgent
    {
        $instructions = $systemPrompt ?? $agent->prompt_template ?? '';

        // Ground every agent (DB agents, chatbots, RAG, team orchestration) in
        // the current UTC datetime — a model has no clock, and this is the one
        // chokepoint all of them funnel through.
        $instructions = CurrentDateTime::promptLine()."\n\n".$instructions;

        // Every internal agent run gets the MCP catalogue as built-in platform
        // tools, scoped to the agent's owner. Skipped for routing/triage runs,
        // which must only see their handoff tools.
        if ($platformTools && ($owner = $this->resolveUser($agent)) !== null) {
            $tools = PlatformToolsFactory::merge($tools, $owner);
        }

        $sdkAgent = new RuntimeAgent($instructions, $messages, $tools);

        // Reasoning is off by default (RuntimeAgent); a DB agent whose owner
        // configured it overrides for that agent only. Non-agent callers
        // (chatbots, triage) leave it null → stays off.
        if ($agent instanceof Agent && $agent->reasoning !== null) {
            $sdkAgent->withReasoning($agent->reasoning);
        }

        // The instructions + tool block are byte-stable across the round-trips
        // of an agentic turn (and across turns), so mark them cacheable — on
        // Anthropic the system breakpoint also covers the tools rendered before
        // it, which is most of the prompt for tool-heavy agents.
        if (config('ai.prompt_caching.enabled')) {
            $sdkAgent->withCacheableSystem($instructions);
        }

        return $sdkAgent;
    }

    /**
     * Content of the last user message in a conversation array, or '' if none.
     *
     * @param  array<Message>  $messages
     */
    private function lastUserMessageContent(array $messages): string
    {
        $lastUserMessage = collect($messages)
            ->filter(fn ($m) => ($m->role instanceof MessageRole ? $m->role : MessageRole::from($m->role)) === MessageRole::User)
            ->last();

        return $lastUserMessage?->content ?? '';
    }

    /**
     * Split messages into history (SDK Message objects) and prompt string.
     *
     * The SDK's prompt() and stream() methods take a string prompt as the
     * latest user message, so we extract the last user message content
     * and convert the rest to SDK Message objects.
     *
     * @param  array<Message>  $messages
     * @return array{0: array<UserMessage|AssistantMessage>, 1: string}
     */
    private function splitMessages(array $messages): array
    {
        $formatted = $this->formatMessages($messages);

        if (empty($formatted)) {
            return [[], ''];
        }

        $last = end($formatted);

        // If the last message is from the user, extract it as the prompt
        if ($last instanceof UserMessage) {
            $history = array_slice($formatted, 0, -1);

            return [$history, $last->content ?? ''];
        }

        // If the last message is from the assistant, keep all as history
        return [$formatted, ''];
    }

    /**
     * Format conversation messages for the SDK (user and assistant only).
     *
     * Anthropic requires:
     * - Alternating user/assistant messages
     * - No empty messages
     * - First message must be from user
     *
     * @param  array<Message>  $messages
     * @return array<UserMessage|AssistantMessage>
     */
    private function formatMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            $role = $message->role instanceof MessageRole
                ? $message->role
                : MessageRole::from($message->role);

            if ($role === MessageRole::System) {
                continue;
            }

            $content = trim($message->content ?? '');
            if ($content === '') {
                continue;
            }

            // Merge consecutive messages from the same role
            if (! empty($normalized)) {
                $lastIndex = count($normalized) - 1;
                if ($normalized[$lastIndex]['role'] === $role) {
                    $normalized[$lastIndex]['content'] .= "\n\n".$content;

                    continue;
                }
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        // Convert to SDK message objects
        $formatted = [];
        foreach ($normalized as $msg) {
            $formatted[] = match ($msg['role']) {
                MessageRole::User => new UserMessage($msg['content']),
                MessageRole::Assistant => new AssistantMessage($msg['content']),
                default => null,
            };
        }

        return array_filter($formatted);
    }
}
