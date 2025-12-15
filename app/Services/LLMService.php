<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Message;
use Generator;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class LLMService
{
    private ?RetrievalService $retrievalService = null;

    /**
     * Map model IDs to Prism providers.
     */
    private const MODEL_PROVIDERS = [
        'claude-sonnet-4-20250514' => Provider::Anthropic,
        'claude-opus-4-20250514' => Provider::Anthropic,
        'claude-3-5-haiku-20241022' => Provider::Anthropic,
        'gpt-4o' => Provider::OpenAI,
        'gpt-4o-mini' => Provider::OpenAI,
        'gpt-4-turbo' => Provider::OpenAI,
    ];

    public function __construct(
        private Prism $prism
    ) {}

    /**
     * Get the provider for a given model.
     */
    public function getProvider(string $model): Provider
    {
        return self::MODEL_PROVIDERS[$model] ?? Provider::Anthropic;
    }

    /**
     * Send a chat message and get a response (non-streaming).
     *
     * @param  array<Message>  $messages
     */
    public function chat(Agent $agent, array $messages): string
    {
        $request = $this->buildRequest($agent, $messages);

        $response = $request->asText();

        return $response->text;
    }

    /**
     * Stream a chat response.
     *
     * Note: PHP-FPM doesn't support Guzzle's HTTP streaming properly,
     * so we use non-streaming mode as fallback. Octane (FrankenPHP/Swoole/RoadRunner)
     * and CLI support true streaming.
     *
     * @param  array<Message>  $messages
     * @return Generator<string>
     */
    public function streamChat(Agent $agent, array $messages): Generator
    {
        $formattedMessages = $this->formatMessages($messages);
        $request = $this->buildRequestWithMessages($agent, $formattedMessages);

        // PHP-FPM has issues with Guzzle/HTTP streaming - use non-streaming fallback
        // Octane (frankenphp, cli-server, etc.) and CLI support streaming
        if (PHP_SAPI === 'fpm-fcgi' || PHP_SAPI === 'cgi-fcgi') {
            $response = $request->asText();
            yield $response->text;

            return;
        }

        // Octane and CLI can use true streaming
        foreach ($request->asStream() as $event) {
            if ($event instanceof TextDeltaEvent) {
                yield $event->delta;
            }
        }
    }

    /**
     * Stream a chat response with RAG (Retrieval Augmented Generation).
     *
     * This method automatically retrieves relevant context from the agent's
     * knowledge bases and injects it into the system prompt.
     *
     * @param  array<Message>  $messages
     * @param  string|null  $userQuery  The query to use for retrieval (defaults to last user message)
     * @return Generator<string>
     */
    public function streamChatWithRAG(Agent $agent, array $messages, ?string $userQuery = null): Generator
    {
        // Get the agent's knowledge base IDs
        $knowledgeBaseIds = $agent->knowledgeBases()->pluck('knowledge_bases.id')->toArray();

        // If no knowledge bases, fall back to regular chat
        if (empty($knowledgeBaseIds)) {
            yield from $this->streamChat($agent, $messages);

            return;
        }

        // Determine the query for retrieval
        if ($userQuery === null) {
            // Use the last user message as the query
            $lastUserMessage = collect($messages)
                ->filter(fn ($m) => ($m->role instanceof MessageRole ? $m->role : MessageRole::from($m->role)) === MessageRole::User)
                ->last();

            $userQuery = $lastUserMessage?->content ?? '';
        }

        if (empty(trim($userQuery))) {
            yield from $this->streamChat($agent, $messages);

            return;
        }

        // Retrieve relevant context
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
        ]);

        // If no relevant context found, fall back to regular chat
        if (empty($retrieval['context'])) {
            yield from $this->streamChat($agent, $messages);

            return;
        }

        // Build augmented system prompt
        $augmentedSystemPrompt = $this->buildAugmentedSystemPrompt(
            $agent->prompt_template ?? '',
            $retrieval['context']
        );

        // Build request with augmented prompt
        $formattedMessages = $this->formatMessages($messages);
        $request = $this->buildRequestWithSystemPrompt($agent, $formattedMessages, $augmentedSystemPrompt);

        // PHP-FPM fallback
        if (PHP_SAPI === 'fpm-fcgi' || PHP_SAPI === 'cgi-fcgi') {
            $response = $request->asText();
            yield $response->text;

            return;
        }

        // True streaming
        foreach ($request->asStream() as $event) {
            if ($event instanceof TextDeltaEvent) {
                yield $event->delta;
            }
        }
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
     * Build a Prism request configured for the agent.
     *
     * @param  array<Message>  $messages
     */
    private function buildRequest(Agent $agent, array $messages): PendingRequest
    {
        return $this->buildRequestWithMessages($agent, $this->formatMessages($messages));
    }

    /**
     * Build a Prism request with pre-formatted messages.
     *
     * @param  array<UserMessage|AssistantMessage>  $formattedMessages
     */
    private function buildRequestWithMessages(Agent $agent, array $formattedMessages): PendingRequest
    {
        $provider = $this->getProvider($agent->model);

        $request = $this->prism->text()
            ->using($provider, $agent->model);

        // Add system prompt separately (required for Anthropic)
        if ($agent->prompt_template) {
            $request->withSystemPrompt($agent->prompt_template);
        }

        // Add conversation messages
        $request->withMessages($formattedMessages);

        // Apply temperature if configured
        $config = $agent->config ?? [];
        if (isset($config['temperature'])) {
            $request->usingTemperature((float) $config['temperature']);
        }

        // Apply max tokens (required for Anthropic)
        $maxTokens = $config['max_tokens'] ?? $this->getDefaultMaxTokens($provider);
        $request->withMaxTokens($maxTokens);

        return $request;
    }

    /**
     * Build a Prism request with a custom system prompt (for RAG).
     *
     * @param  array<UserMessage|AssistantMessage>  $formattedMessages
     */
    private function buildRequestWithSystemPrompt(Agent $agent, array $formattedMessages, string $systemPrompt): PendingRequest
    {
        $provider = $this->getProvider($agent->model);

        $request = $this->prism->text()
            ->using($provider, $agent->model);

        // Add the augmented system prompt
        if (! empty($systemPrompt)) {
            $request->withSystemPrompt($systemPrompt);
        }

        // Add conversation messages
        $request->withMessages($formattedMessages);

        // Apply temperature if configured
        $config = $agent->config ?? [];
        if (isset($config['temperature'])) {
            $request->usingTemperature((float) $config['temperature']);
        }

        // Apply max tokens (required for Anthropic)
        $maxTokens = $config['max_tokens'] ?? $this->getDefaultMaxTokens($provider);
        $request->withMaxTokens($maxTokens);

        return $request;
    }

    /**
     * Get default max tokens for a provider.
     */
    private function getDefaultMaxTokens(Provider $provider): int
    {
        return match ($provider) {
            Provider::Anthropic => 4096,
            Provider::OpenAI => 4096,
            default => 2048,
        };
    }

    /**
     * Format conversation messages for Prism (user and assistant only).
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

            // Skip system messages and empty content
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

        // Convert to Prism message objects
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
