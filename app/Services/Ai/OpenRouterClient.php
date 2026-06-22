<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Direct OpenRouter chat-completions client for multimodal capability handoff.
 *
 * The Laravel AI SDK's Image/Audio/Transcription/Reranking classes don't route
 * through OpenRouter, but OpenRouter exposes every multimodal task — image and
 * PDF understanding (OCR), audio input (transcription) and image OUTPUT — through
 * its single OpenAI-compatible /chat/completions endpoint, per
 * https://openrouter.ai/docs/guides/overview/multimodal/overview. When a
 * capability's configured model is an OpenRouter model, the tools call this
 * client instead of the SDK.
 */
class OpenRouterClient
{
    public function __construct(private readonly AiProviderService $providers) {}

    public function isConfiguredFor(User $user): bool
    {
        return $this->apiKey($user) !== '';
    }

    /**
     * POST a single user-turn chat completion and return the decoded response.
     *
     * @param  array<int, array<string, mixed>>  $content  the message content blocks
     * @param  array<string, mixed>  $extra  extra body fields (e.g. ['modalities' => ['image','text']])
     * @return array<string, mixed>
     */
    public function chat(User $user, string $model, array $content, array $extra = [], int $timeout = 120): array
    {
        $key = $this->apiKey($user);
        if ($key === '') {
            throw new RuntimeException('No OpenRouter API key is configured.');
        }

        $base = rtrim((string) (config('ai.providers.openrouter.url') ?: AiProviderService::OPENROUTER_BASE_URL), '/');

        $response = Http::withToken($key)
            ->timeout($timeout)
            ->post($base.'/chat/completions', array_merge([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $content]],
            ], $extra));

        if (! $response->successful()) {
            throw new RuntimeException('OpenRouter request failed ('.$response->status().'): '.$response->body());
        }

        return (array) $response->json();
    }

    /** @return array{type: string, text: string} */
    public static function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** @return array{type: string, image_url: array{url: string}} */
    public static function imageBlock(string $url): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => $url]];
    }

    /** @return array{type: string, file: array{url: string}} */
    public static function fileBlock(string $dataUrl): array
    {
        return ['type' => 'file', 'file' => ['url' => $dataUrl]];
    }

    /** @return array{type: string, input_audio: array{data: string, format: string}} */
    public static function audioBlock(string $base64, string $format): array
    {
        return ['type' => 'input_audio', 'input_audio' => ['data' => $base64, 'format' => $format]];
    }

    /**
     * The assistant's text reply.
     *
     * @param  array<string, mixed>  $response
     */
    public static function text(array $response): string
    {
        return (string) data_get($response, 'choices.0.message.content', '');
    }

    /**
     * The first generated image as a base64 data URL, or null.
     *
     * @param  array<string, mixed>  $response
     */
    public static function firstImageDataUrl(array $response): ?string
    {
        $url = data_get($response, 'choices.0.message.images.0.image_url.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function apiKey(User $user): string
    {
        // Resolve tenant → global → env the same way the SDK is configured.
        $this->providers->applyRuntimeConfig($user);

        return (string) config('ai.providers.openrouter.key', '');
    }
}
