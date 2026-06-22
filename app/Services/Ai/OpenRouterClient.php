<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
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

    /**
     * PDF processing engines for the file-parser plugin, per
     * https://openrouter.ai/docs/guides/overview/multimodal/pdfs.
     */
    public const PDF_ENGINES = ['mistral-ocr', 'cloudflare-ai', 'native'];

    public const DEFAULT_PDF_ENGINE = 'mistral-ocr';

    /** @return array{type: string, file: array{filename: string, file_data: string}} */
    public static function fileBlock(string $dataUrl, string $filename = 'document.pdf'): array
    {
        return ['type' => 'file', 'file' => ['filename' => $filename, 'file_data' => $dataUrl]];
    }

    /**
     * The `file-parser` plugin body that selects the PDF processing engine.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pdfPlugins(string $engine): array
    {
        return [['id' => 'file-parser', 'pdf' => ['engine' => $engine]]];
    }

    /** The admin-configured OCR-PDF engine (admin AI > Defaults), or the default. */
    public static function configuredPdfEngine(): string
    {
        $engine = (string) AppSetting::getValue('admin_v2.ai.ocr_pdf.engine', self::DEFAULT_PDF_ENGINE);

        return in_array($engine, self::PDF_ENGINES, true) ? $engine : self::DEFAULT_PDF_ENGINE;
    }

    /** @return array{type: string, input_audio: array{data: string, format: string}} */
    public static function audioBlock(string $base64, string $format): array
    {
        return ['type' => 'input_audio', 'input_audio' => ['data' => $base64, 'format' => $format]];
    }

    /**
     * The assistant's text reply. Handles both a plain string content and the
     * multimodal array-of-parts shape (joining the text parts).
     *
     * @param  array<string, mixed>  $response
     */
    public static function text(array $response): string
    {
        $content = data_get($response, 'choices.0.message.content');

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return collect($content)
                ->map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part)
                ->implode('');
        }

        return '';
    }

    /**
     * A short reason the response carried no text — an upstream error or the
     * model's finish_reason — for surfacing a useful message instead of a blank.
     *
     * @param  array<string, mixed>  $response
     */
    public static function failureReason(array $response): string
    {
        $error = data_get($response, 'error.message');
        $finish = data_get($response, 'choices.0.finish_reason');

        $parts = array_filter([
            is_string($error) && $error !== '' ? "error: {$error}" : null,
            is_string($finish) && $finish !== '' ? "finish_reason: {$finish}" : null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : 'the model returned an empty response';
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
