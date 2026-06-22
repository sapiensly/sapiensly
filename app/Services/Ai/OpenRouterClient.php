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

    /**
     * Generate audio (TTS) via OpenRouter. Audio output requires stream:true, and
     * streamed output only supports the raw `pcm16` format — so we stream the SSE
     * response, concatenate the base64 PCM deltas, and wrap the PCM in a WAV
     * container so the result is a playable file.
     *
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>  $audioParams  e.g. ['voice' => 'alloy']
     * @return array{bytes: string, mime: string}|null
     */
    public function audio(User $user, string $model, array $content, array $audioParams, int $timeout = 120): ?array
    {
        $key = $this->apiKey($user);
        if ($key === '') {
            throw new RuntimeException('No OpenRouter API key is configured.');
        }

        $base = rtrim((string) (config('ai.providers.openrouter.url') ?: AiProviderService::OPENROUTER_BASE_URL), '/');

        $response = Http::withToken($key)
            ->timeout($timeout)
            ->withOptions(['stream' => true])
            ->post($base.'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $content]],
                'modalities' => ['audio', 'text'],
                // Streamed audio output only supports raw PCM (pcm16).
                'audio' => $audioParams + ['format' => 'pcm16'],
                'stream' => true,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenRouter audio request failed ('.$response->status().'): '.$response->body());
        }

        $body = $response->toPsrResponse()->getBody();
        $b64 = '';
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $json = json_decode($data, true);
                if (! is_array($json)) {
                    continue;
                }
                $delta = data_get($json, 'choices.0.delta.audio.data');
                if (is_string($delta)) {
                    $b64 .= $delta;
                }
            }
        }

        if ($b64 === '') {
            return null;
        }

        $pcm = base64_decode($b64) ?: '';

        // OpenAI streamed audio is 24kHz mono 16-bit PCM; wrap it as WAV.
        return ['bytes' => self::pcm16ToWav($pcm), 'mime' => 'audio/wav'];
    }

    /** Wrap raw PCM16 audio in a minimal WAV container so it's a playable file. */
    private static function pcm16ToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bits = 16): string
    {
        $byteRate = (int) ($sampleRate * $channels * $bits / 8);
        $blockAlign = (int) ($channels * $bits / 8);
        $dataLen = strlen($pcm);

        $header = 'RIFF'.pack('V', 36 + $dataLen).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels).pack('V', $sampleRate)
            .pack('V', $byteRate).pack('v', $blockAlign).pack('v', $bits)
            .'data'.pack('V', $dataLen);

        return $header.$pcm;
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
     * The parsed PDF text from the file-parser plugin's annotations
     * (choices[0].message.annotations[].file.content[].text). This — not the
     * model's chat reply — is the authoritative OCR output, per
     * https://openrouter.ai/docs/guides/overview/multimodal/pdfs.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fileAnnotationText(array $response): string
    {
        $annotations = data_get($response, 'choices.0.message.annotations', []);
        if (! is_array($annotations)) {
            return '';
        }

        $out = [];
        foreach ($annotations as $annotation) {
            $content = data_get($annotation, 'file.content');
            if (is_string($content)) {
                $out[] = $content;

                continue;
            }
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? null) === 'text') {
                        $out[] = (string) ($part['text'] ?? '');
                    } elseif (is_string($part)) {
                        $out[] = $part;
                    }
                }
            }
        }

        return trim(implode("\n", array_filter($out)));
    }

    /**
     * The parsed PDF as markdown with the extracted figures inlined. The OCR
     * markdown references images by filename (e.g. `![img-0.jpeg](img-0.jpeg)`),
     * while the actual image bytes arrive as separate `image_url` parts in the
     * annotation content — so we splice each data URL into its placeholder (in
     * order) and drop any reference without a matching image (avoiding 404s).
     *
     * @param  array<string, mixed>  $response
     */
    public static function fileAnnotationMarkdown(array $response): string
    {
        $annotations = data_get($response, 'choices.0.message.annotations', []);
        if (! is_array($annotations)) {
            return '';
        }

        $texts = [];
        $images = [];
        foreach ($annotations as $annotation) {
            $content = data_get($annotation, 'file.content');
            if (is_string($content)) {
                $texts[] = $content;

                continue;
            }
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (! is_array($part)) {
                    if (is_string($part)) {
                        $texts[] = $part;
                    }

                    continue;
                }
                if (($part['type'] ?? null) === 'text') {
                    $texts[] = (string) ($part['text'] ?? '');
                } elseif (($part['type'] ?? null) === 'image_url') {
                    $url = (string) data_get($part, 'image_url.url', '');
                    if ($url !== '') {
                        $images[] = $url;
                    }
                }
            }
        }

        $markdown = trim(implode("\n", array_filter($texts)));
        if ($images === []) {
            return $markdown;
        }

        // Splice each extracted image into its markdown placeholder, in order.
        $i = 0;
        $markdown = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\([^)]*\)/',
            function (array $m) use (&$i, $images): string {
                $url = $images[$i] ?? null;
                $i++;

                return $url !== null ? '!['.$m[1].']('.$url.')' : '';
            },
            $markdown,
        );

        // Append any images that had no placeholder reference.
        for (; $i < count($images); $i++) {
            $markdown .= "\n\n![]({$images[$i]})";
        }

        return trim($markdown);
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

    /**
     * Generated audio (TTS) as a base64 data URL, or null. OpenRouter returns it
     * at choices[0].message.audio.data (base64) with an optional format.
     *
     * @param  array<string, mixed>  $response
     */
    public static function firstAudioDataUrl(array $response): ?string
    {
        $data = data_get($response, 'choices.0.message.audio.data');
        if (! is_string($data) || $data === '') {
            return null;
        }

        // Already a data URL on some providers; otherwise wrap the base64.
        if (str_starts_with($data, 'data:')) {
            return $data;
        }

        $format = strtolower((string) (data_get($response, 'choices.0.message.audio.format') ?: 'mp3'));
        $mime = match ($format) {
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            'opus' => 'audio/opus',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            default => 'audio/mpeg',
        };

        return 'data:'.$mime.';base64,'.$data;
    }

    private function apiKey(User $user): string
    {
        // Resolve tenant → global → env the same way the SDK is configured.
        $this->providers->applyRuntimeConfig($user);

        return (string) config('ai.providers.openrouter.key', '');
    }
}
