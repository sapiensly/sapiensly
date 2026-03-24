<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Message;
use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
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
     * Get the provider for a given model, resolved from DB-configured providers.
     */
    public function getProvider(string $model, ?Agent $agent = null): Lab
    {
        $user = $this->resolveUser($agent);

        if ($user) {
            return $this->getAiProviderService()->resolveProvider($model, $user);
        }

        return Lab::Anthropic;
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
     */
    public function chat(Agent $agent, array $messages): string
    {
        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history);

        $response = $sdkAgent->prompt(
            $prompt,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
        );

        return $response->text;
    }

    /**
     * Stream a chat response.
     *
     * @param  array<Message>  $messages
     * @return Generator<string>
     */
    public function streamChat(Agent $agent, array $messages): Generator
    {
        [$history, $prompt] = $this->splitMessages($messages);
        $sdkAgent = $this->buildAgent($agent, $history);

        $response = $sdkAgent->stream(
            $prompt,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
        );

        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                yield $event->delta;
            }
        }
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
     * @return array{generator: Generator<string>, knowledge_bases: array<array{id: string, name: string}>, chunk_count: int}
     */
    public function streamChatWithRAGInfo(Agent $agent, array $messages, ?string $userQuery = null): array
    {
        $knowledgeBaseIds = $agent->knowledgeBases()->pluck('knowledge_bases.id')->toArray();

        if (empty($knowledgeBaseIds)) {
            return [
                'generator' => $this->streamChat($agent, $messages),
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
                'generator' => $this->streamChat($agent, $messages),
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
                'generator' => $this->streamChat($agent, $messages),
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

        $generator = (function () use ($sdkAgent, $prompt, $agent) {
            $response = $sdkAgent->stream(
                $prompt,
                provider: $this->getProvider($agent->model, $agent),
                model: $agent->model,
            );

            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    yield $event->delta;
                }
            }
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
    public function chatWithTools(Agent $agent, array $messages, int $maxSteps = 5): AgentResponse
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
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
        );

        $lastStep = $response->steps->last();

        Log::info('Tool chat completed', [
            'agent_id' => $agent->id,
            'steps' => $response->steps->count(),
            'finish_reason' => $lastStep?->finishReason?->name ?? 'unknown',
        ]);

        return $response;
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
        $sdkAgent = $this->buildAgent($agent, $history, $tools);

        $response = $sdkAgent->prompt(
            $prompt,
            provider: $this->getProvider($agent->model, $agent),
            model: $agent->model,
        );

        $lastStep = $response->steps->last();

        Log::info('Routing chat completed', [
            'agent_id' => $agent->id,
            'steps' => $response->steps->count(),
            'finish_reason' => $lastStep?->finishReason?->name ?? 'unknown',
        ]);

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
     * Build an AnonymousAgent configured for the given agent.
     *
     * @param  array<UserMessage|AssistantMessage>  $messages
     * @param  array<Tool>  $tools
     */
    private function buildAgent(Agent $agent, array $messages, array $tools = [], ?string $systemPrompt = null): AnonymousAgent
    {
        $instructions = $systemPrompt ?? $agent->prompt_template ?? '';

        return new AnonymousAgent($instructions, $messages, $tools);
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
