<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Message;
use Generator;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class LLMService
{
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
     * so we fall back to non-streaming mode in that context.
     *
     * @param  array<Message>  $messages
     * @return Generator<string>
     */
    public function streamChat(Agent $agent, array $messages): Generator
    {
        $formattedMessages = $this->formatMessages($messages);
        $request = $this->buildRequestWithMessages($agent, $formattedMessages);

        // PHP-FPM has issues with Guzzle streaming, use non-streaming fallback
        if (PHP_SAPI === 'fpm-fcgi') {
            $response = $request->asText();
            yield $response->text;

            return;
        }

        // CLI and other SAPIs can use true streaming
        foreach ($request->asStream() as $event) {
            if ($event instanceof TextDeltaEvent) {
                yield $event->delta;
            }
        }
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
