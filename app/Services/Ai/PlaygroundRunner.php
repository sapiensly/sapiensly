<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Transcription;
use RuntimeException;

/**
 * Runs a single, ad-hoc test of one AI capability for the Playground module.
 *
 * It resolves the handler model the same way the agent handoff does (the admin
 * default, or a user-chosen override), then executes the capability against the
 * provided input — reusing {@see OpenRouterClient} for OpenRouter models (whose
 * multimodal tasks go through chat completions) and the Laravel AI SDK otherwise.
 * Binary outputs (generated images, synthesized speech) are returned inline as
 * base64 data URLs — the Playground is ephemeral, nothing is persisted.
 */
class PlaygroundRunner
{
    /**
     * Capability registry: the single source of truth shared with the controller
     * (page props) and the runner. `default` is the admin-defaults category whose
     * configured model is used; `catalog` is the capability whose enabled models
     * are eligible as an override.
     *
     * @var array<string, array{default: string, catalog: string, input: string, output: string}>
     */
    public const CAPABILITIES = [
        'text' => ['default' => 'chat', 'catalog' => 'chat', 'input' => 'prompt', 'output' => 'text'],
        'coding' => ['default' => 'coding', 'catalog' => 'chat', 'input' => 'prompt', 'output' => 'text'],
        'embeddings' => ['default' => 'embeddings', 'catalog' => 'embeddings', 'input' => 'text', 'output' => 'embeddings'],
        'ocr_pdf' => ['default' => 'ocr_pdf', 'catalog' => 'vision', 'input' => 'pdf', 'output' => 'text'],
        'image_vision' => ['default' => 'image_vision', 'catalog' => 'vision', 'input' => 'image_q', 'output' => 'text'],
        'image_generation' => ['default' => 'image_generation', 'catalog' => 'image', 'input' => 'prompt', 'output' => 'image'],
        'audio_recognition' => ['default' => 'audio_recognition', 'catalog' => 'transcription', 'input' => 'audio', 'output' => 'text'],
        'speech_generation' => ['default' => 'speech_generation', 'catalog' => 'speech', 'input' => 'text', 'output' => 'audio'],
        'reranking' => ['default' => 'reranking', 'catalog' => 'rerank', 'input' => 'rerank', 'output' => 'rerank'],
    ];

    public function __construct(
        private readonly AiCapabilities $capabilities,
        private readonly AiDefaults $defaults,
        private readonly OpenRouterClient $openRouter,
        private readonly AiSpendGuard $spendGuard,
        private readonly AiPricing $pricing,
    ) {}

    /**
     * Resolve the model+provider for a capability run: a user-chosen override
     * (validated to the right capability), else the admin default, else the
     * capability's hard default. Throws when nothing is configured.
     *
     * @return array{model: string, driver: string, provider: Lab, input_price: ?float, output_price: ?float}
     */
    public function resolveForRun(string $capability, ?string $explicitModelId): array
    {
        $meta = self::CAPABILITIES[$capability] ?? throw new RuntimeException("Unknown capability '{$capability}'.");

        if ($explicitModelId !== null && $explicitModelId !== '') {
            $row = AiCatalogModel::query()->enabled()->whereKey($explicitModelId)->first(['model_id', 'driver', 'capability']);
            // OCR also accepts any OpenRouter model (PDF via file-parser, image via vision).
            $ocrOpenRouter = in_array($capability, ['ocr_pdf', 'image_vision', 'image_generation'], true) && $row?->driver === 'openrouter';
            if ($row === null || ! ($row->capability === $meta['catalog'] || $ocrOpenRouter)) {
                throw new RuntimeException('The selected model is not an enabled '.$meta['catalog'].' model.');
            }

            return $this->withProvider((string) $row->model_id, (string) $row->driver);
        }

        if ($resolved = $this->capabilities->resolve($meta['default'])) {
            return $this->withProvider($resolved['model'], $resolved['driver']);
        }

        $hard = $this->defaults->hardDefaultFor($meta['default']);
        if ($hard !== null) {
            $row = AiCatalogModel::query()->enabled()
                ->where('model_id', $hard)
                ->where('capability', $meta['catalog'])
                ->first(['model_id', 'driver']);
            if ($row !== null) {
                return $this->withProvider((string) $row->model_id, (string) $row->driver);
            }
        }

        throw new RuntimeException('No model is configured for this capability. Set one in admin AI > Defaults.');
    }

    /**
     * Execute a capability run and return a structured result.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $user, string $capability, ?string $modelId, array $input, ?UploadedFile $file): array
    {
        $this->usage = null;
        $this->raw = null;
        $this->ttftMs = null;
        $this->lastHandler = null;
        $handler = $this->resolveForRun($capability, $modelId);
        $this->lastHandler = ['model' => $handler['model'], 'driver' => $handler['driver']];
        $this->spendGuard->assertWithinBudget($user, $user->organization_id, $handler['model']);

        // Reasoning control (OpenRouter only): 'off'|'low'|'medium'|'high', or
        // null/'default' to leave the model's own default.
        $reasoning = is_string($input['reasoning'] ?? null) ? $input['reasoning'] : null;

        $output = match ($capability) {
            'text' => ['text' => $this->generateText($user, $handler, (string) ($input['prompt'] ?? ''), 'You are a helpful assistant. Answer the user clearly.', $reasoning)],
            'coding' => ['text' => $this->generateText($user, $handler, (string) ($input['prompt'] ?? ''), 'You are an expert programming assistant. Return correct, idiomatic code with a brief explanation.', $reasoning)],
            'embeddings' => $this->embeddings($handler, (string) ($input['text'] ?? '')),
            'ocr_pdf' => ['text' => $this->ocrPdf($user, $handler, $this->requireFile($file))],
            'image_vision' => ['text' => $this->imageVision($user, $handler, $this->requireFile($file), (string) ($input['prompt'] ?? ''))],
            'image_generation' => ['image' => $this->generateImage($user, $handler, (string) ($input['prompt'] ?? ''))],
            'audio_recognition' => ['text' => $this->transcribe($user, $handler, $this->requireFile($file))],
            'speech_generation' => ['audio' => $this->synthesizeSpeech(
                $user,
                $handler,
                (string) ($input['text'] ?? ''),
                (string) ($input['voice'] ?? ''),
                (string) ($input['gender'] ?? ''),
                (string) ($input['instructions'] ?? ''),
            )],
            'reranking' => $this->rerank($handler, (string) ($input['query'] ?? ''), (array) ($input['documents'] ?? [])),
            default => throw new RuntimeException("Unknown capability '{$capability}'."),
        };

        $meta = ['model' => $handler['model'], 'driver' => $handler['driver']];
        if ($this->usage !== null) {
            $meta['usage'] = $this->usage;
        }
        // OpenRouter is a broker: its raw response names the upstream provider
        // that actually served the request — benchmark-relevant, surface it.
        if (is_string($served = data_get($this->raw, 'provider')) && $served !== '') {
            $meta['served_by'] = $served;
        }
        if ($this->ttftMs !== null) {
            $meta['ttft_ms'] = $this->ttftMs;
        }

        return $meta + $output;
    }

    /** Token usage + cost for the current run, populated by the capability methods. */
    private ?array $usage = null;

    /** Raw provider response for the current run, where the transport exposes it (OpenRouter). */
    private ?array $raw = null;

    /** Time-to-first-token (ms) for the current run, when the call was streamed. */
    private ?int $ttftMs = null;

    /** Model+driver resolved for the current run — available even when execution then fails. */
    private ?array $lastHandler = null;

    /**
     * Raw provider response of the last {@see execute()} call, or null when the
     * transport does not expose one (SDK response objects).
     *
     * @return array<string, mixed>|null
     */
    public function rawResponse(): ?array
    {
        return $this->raw;
    }

    /**
     * Model+driver resolved by the last {@see execute()} call — set as soon as
     * resolution succeeds, so error runs can still be attributed to a model.
     *
     * @return array{model: string, driver: string}|null
     */
    public function lastHandler(): ?array
    {
        return $this->lastHandler;
    }

    /** @return array{model: string, driver: string, provider: Lab, input_price: ?float, output_price: ?float} */
    private function withProvider(string $model, string $driver): array
    {
        $provider = Lab::tryFrom($driver) ?? throw new RuntimeException("Unsupported provider driver '{$driver}'.");

        $row = AiCatalogModel::query()
            ->where('model_id', $model)
            ->where('driver', $driver)
            ->first(['input_price_per_mtok', 'output_price_per_mtok']);

        return [
            'model' => $model,
            'driver' => $driver,
            'provider' => $provider,
            'input_price' => $row?->input_price_per_mtok,
            'output_price' => $row?->output_price_per_mtok,
        ];
    }

    /**
     * Record token usage + cost for the current run. An explicit $cost (e.g. the
     * amount a broker like OpenRouter already reports) is authoritative; when
     * none is given, cost is derived from the catalog's per-Mtok prices.
     *
     * @param  array{input_price: ?float, output_price: ?float, ...}  $handler
     */
    private function recordUsage(array $handler, ?int $prompt, ?int $completion, bool $estimated = false, ?float $cost = null): void
    {
        if ($cost === null) {
            $ip = $handler['input_price'] ?? null;
            $op = $handler['output_price'] ?? null;

            if ($ip !== null || $op !== null) {
                $cost = round((($prompt ?? 0) / 1_000_000) * (float) ($ip ?? 0)
                    + (($completion ?? 0) / 1_000_000) * (float) ($op ?? 0), 6);
            }
        }

        $total = ($prompt ?? 0) + ($completion ?? 0);

        $this->usage = array_filter([
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total > 0 ? $total : null,
            'cost' => $cost,
            'estimated' => $estimated ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Record usage for a Laravel AI SDK response. Cost is priced through
     * {@see AiPricing} so prompt-cache economics are applied — cached-read input
     * bills at ~0.1x and cache writes at ~1.25x, which the flat input+output
     * math would get wrong for cached calls. Cache-read/write and reasoning
     * token counts are captured too. An unpriced model keeps a null cost
     * ("unknown"), never a misleading $0.
     */
    private function recordSdkUsage(array $handler, Usage $usage): void
    {
        $total = $usage->promptTokens + $usage->completionTokens;

        $cost = $this->pricing->pricesFor($handler['model']) !== null
            ? round($this->pricing->costFor($handler['model'], $usage), 6)
            : null;

        $this->usage = array_filter([
            'prompt_tokens' => $usage->promptTokens ?: null,
            'completion_tokens' => $usage->completionTokens ?: null,
            'total_tokens' => $total > 0 ? $total : null,
            'cache_read_tokens' => $usage->cacheReadInputTokens ?: null,
            'cache_write_tokens' => $usage->cacheWriteInputTokens ?: null,
            'reasoning_tokens' => $usage->reasoningTokens ?: null,
            'cost' => $cost,
        ], fn ($v) => $v !== null);
    }

    /**
     * Pull token usage out of a direct OpenRouter chat response, keeping the
     * full payload as the run's raw provider response.
     *
     * @param  array{input_price: ?float, output_price: ?float, ...}  $handler
     * @param  array<string, mixed>  $response
     */
    private function recordOpenRouterUsage(array $handler, array $response): void
    {
        $this->raw = $response;

        $usage = data_get($response, 'usage');
        if (! is_array($usage)) {
            return;
        }

        // OpenRouter reports the actual billed cost — authoritative, and
        // independent of whether the catalog carries per-Mtok prices for this
        // brokered model. Fall back to catalog-derived cost when it is absent.
        $reportedCost = $usage['cost'] ?? null;

        $this->recordUsage(
            $handler,
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            cost: is_numeric($reportedCost) ? round((float) $reportedCost, 6) : null,
        );
    }

    private function requireFile(?UploadedFile $file): UploadedFile
    {
        return $file ?? throw new RuntimeException('This capability requires a file upload.');
    }

    /** @param array{model: string, driver: string, provider: Lab} $handler */
    private function generateText(User $user, array $handler, string $prompt, string $system, ?string $reasoning = null): string
    {
        if (trim($prompt) === '') {
            throw new RuntimeException('Provide a prompt.');
        }

        if ($handler['driver'] === 'openrouter') {
            // Streamed chat-completions call: streaming is what makes TTFT
            // measurable, and the raw payload still names the upstream provider
            // OpenRouter routed to. Reuses the same telemetry recording as the
            // blocking path via the reconstructed response. The reasoning field
            // (when set) tunes/disables the model's thinking — OpenRouter only.
            $stream = $this->openRouter->chatStreamed($user, $handler['model'], [], [
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => [OpenRouterClient::textBlock($prompt)]],
                ],
            ] + OpenRouterClient::reasoningParams($reasoning, $handler['model']), timeout: (int) config('ai.request_timeout', 180));
            $this->recordOpenRouterUsage($handler, $stream['response']);
            $this->ttftMs = $stream['ttft_ms'];

            return $stream['text'];
        }

        $response = (new AnonymousAgent($system, [], []))
            ->prompt($prompt, provider: $handler['provider'], model: $handler['model'], timeout: (int) config('ai.request_timeout', 180));
        $this->recordSdkUsage($handler, $response->usage);

        return (string) $response->text;
    }

    /**
     * @param  array{model: string, driver: string, provider: Lab}  $handler
     * @return array{dimensions: int, preview: array<int, float>}
     */
    private function embeddings(array $handler, string $text): array
    {
        if (trim($text) === '') {
            throw new RuntimeException('Provide text to embed.');
        }
        if ($handler['driver'] === 'openrouter') {
            throw new RuntimeException('Embeddings are not available through OpenRouter. Pick a direct embeddings model.');
        }

        $vector = Embeddings::for([$text])->generate($handler['provider'], $handler['model'])->embeddings[0] ?? [];

        // Embeddings responses don't carry token counts — estimate (~4 chars/token).
        $this->recordUsage($handler, (int) ceil(mb_strlen($text) / 4), 0, estimated: true);

        return [
            'dimensions' => count($vector),
            'preview' => array_map(fn ($v) => round((float) $v, 5), array_slice($vector, 0, 8)),
        ];
    }

    /** @param array{model: string, driver: string, provider: Lab} $handler */
    private function ocrPdf(User $user, array $handler, UploadedFile $file): string
    {
        $instruction = 'Extract ALL text from the attached file verbatim, preserving reading order. Output only the extracted text.';

        if ($handler['driver'] === 'openrouter') {
            // PDFs use the file-parser plugin with the admin-configured OCR engine.
            $response = $this->openRouter->chat($user, $handler['model'], [
                OpenRouterClient::textBlock($instruction),
                OpenRouterClient::fileBlock($this->dataUrl($file), $file->getClientOriginalName() ?: 'document.pdf'),
            ], ['plugins' => OpenRouterClient::pdfPlugins(OpenRouterClient::configuredPdfEngine())]);

            // The parsed text comes back in the file-parser annotations (markdown
            // with the extracted figures inlined), not the model's chat reply.
            $text = OpenRouterClient::fileAnnotationMarkdown($response) ?: OpenRouterClient::text($response);

            if (trim($text) === '') {
                throw new RuntimeException(
                    'No text was extracted ('.OpenRouterClient::failureReason($response).'). '
                    .'Check your OpenRouter credits/PDF engine, or pick a vision/OCR model in admin AI > Defaults.',
                );
            }

            $this->recordOpenRouterUsage($handler, $response);

            return $text;
        }

        $response = (new AnonymousAgent($instruction, [], []))
            ->prompt('Extract all text from the attached file.', attachments: [Files\Document::fromPath($file->getRealPath())], provider: $handler['provider'], model: $handler['model'], timeout: (int) config('ai.request_timeout', 180));
        $this->recordSdkUsage($handler, $response->usage);

        return (string) $response->text;
    }

    /**
     * Image understanding: answer the user's question about the image, or — when
     * no question is given — extract its text (OCR). Merged OCR-image + Vision.
     *
     * @param  array{model: string, driver: string, provider: Lab}  $handler
     */
    private function imageVision(User $user, array $handler, UploadedFile $file, string $question): string
    {
        $instruction = trim($question) !== ''
            ? $question
            : 'Extract ALL text from the attached image verbatim, preserving reading order. Output only the extracted text.';

        if ($handler['driver'] === 'openrouter') {
            $response = $this->openRouter->chat($user, $handler['model'], [
                OpenRouterClient::textBlock($instruction),
                OpenRouterClient::imageBlock($this->dataUrl($file)),
            ]);
            $this->recordOpenRouterUsage($handler, $response);

            return OpenRouterClient::text($response);
        }

        $response = (new AnonymousAgent('You are a vision assistant.', [], []))
            ->prompt($instruction, attachments: [Files\Image::fromPath($file->getRealPath())], provider: $handler['provider'], model: $handler['model'], timeout: (int) config('ai.request_timeout', 180));
        $this->recordSdkUsage($handler, $response->usage);

        return (string) $response->text;
    }

    /** @param array{model: string, driver: string, provider: Lab} $handler */
    private function generateImage(User $user, array $handler, string $prompt): string
    {
        if (trim($prompt) === '') {
            throw new RuntimeException('Provide a prompt.');
        }

        if ($handler['driver'] === 'openrouter') {
            $response = $this->openRouter->chat(
                $user,
                $handler['model'],
                [OpenRouterClient::textBlock($prompt)],
                ['modalities' => ['image', 'text']],
            );
            $dataUrl = OpenRouterClient::firstImageDataUrl($response);
            if ($dataUrl === null) {
                throw new RuntimeException('The model returned no image. Pick an OpenRouter model with image output.');
            }
            $this->recordOpenRouterUsage($handler, $response);

            return $dataUrl;
        }

        $image = Image::of($prompt)->generate($handler['provider'], $handler['model']);
        $this->recordSdkUsage($handler, $image->usage);

        return 'data:image/png;base64,'.base64_encode((string) $image);
    }

    /** @param array{model: string, driver: string, provider: Lab} $handler */
    private function transcribe(User $user, array $handler, UploadedFile $file): string
    {
        if ($handler['driver'] === 'openrouter') {
            $response = $this->openRouter->chat($user, $handler['model'], [
                OpenRouterClient::textBlock('Transcribe the attached audio verbatim. Output only the transcript.'),
                OpenRouterClient::audioBlock(base64_encode((string) file_get_contents($file->getRealPath())), $this->audioFormat($file)),
            ]);
            $this->recordOpenRouterUsage($handler, $response);

            return OpenRouterClient::text($response);
        }

        $response = Transcription::fromUpload($file)->generate($handler['provider'], $handler['model']);
        $this->recordSdkUsage($handler, $response->usage);

        return (string) $response->text;
    }

    /**
     * Synthesize speech. Voice controls: $voice picks a provider voice,
     * $gender nudges male/female, and $instructions coaches language / region /
     * accent / style (e.g. "Mexican Spanish, warm and casual").
     *
     * @param  array{model: string, driver: string, provider: Lab}  $handler
     */
    private function synthesizeSpeech(User $user, array $handler, string $text, string $voice, string $gender, string $instructions): string
    {
        if (trim($text) === '') {
            throw new RuntimeException('Provide text to synthesize.');
        }

        if ($handler['driver'] === 'openrouter') {
            // OpenRouter TTS rides on streamed chat completions (audio output
            // requires stream:true); style/language are coached via the prompt.
            $prompt = $instructions !== '' ? $instructions."\n\n".$text : $text;
            $audio = $this->openRouter->audio(
                $user,
                $handler['model'],
                [OpenRouterClient::textBlock($prompt)],
                ['voice' => $voice !== '' ? $voice : 'alloy'],
            );

            if ($audio === null) {
                throw new RuntimeException('The model returned no audio. Pick an OpenRouter model with audio output.');
            }

            return 'data:'.$audio['mime'].';base64,'.base64_encode($audio['bytes']);
        }

        $pending = Audio::of($text);
        if ($voice !== '') {
            $pending = $pending->voice($voice);
        }
        $pending = match ($gender) {
            'male' => $pending->male(),
            'female' => $pending->female(),
            default => $pending,
        };
        if ($instructions !== '') {
            $pending = $pending->instructions($instructions);
        }

        $audio = $pending->generate($handler['provider'], $handler['model']);

        return 'data:audio/mpeg;base64,'.base64_encode((string) $audio);
    }

    /**
     * @param  array{model: string, driver: string, provider: Lab}  $handler
     * @param  array<int, mixed>  $documents
     * @return array{ranked: array<int, array{index: int, score: float, document: string}>}
     */
    private function rerank(array $handler, string $query, array $documents): array
    {
        $docs = array_values(array_filter(array_map('strval', $documents), fn ($d) => trim($d) !== ''));
        if (trim($query) === '' || $docs === []) {
            throw new RuntimeException('Provide a query and at least one document.');
        }
        if ($handler['driver'] === 'openrouter') {
            throw new RuntimeException('Reranking is not available through OpenRouter. Pick a direct rerank provider (Cohere, Voyage or Jina).');
        }

        $response = Reranking::of($docs)->rerank($query, $handler['provider'], $handler['model']);

        return [
            'ranked' => array_map(fn ($r) => [
                'index' => (int) $r->index,
                'score' => round((float) $r->score, 4),
                'document' => (string) $r->document,
            ], $response->results),
        ];
    }

    private function dataUrl(UploadedFile $file): string
    {
        $mime = $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file->getRealPath()));
    }

    private function audioFormat(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');

        return match ($ext) {
            'wav' => 'wav',
            'mp3', 'mpeg', 'mpga' => 'mp3',
            'ogg' => 'ogg',
            'webm' => 'webm',
            'flac' => 'flac',
            'm4a', 'mp4' => 'm4a',
            default => 'mp3',
        };
    }
}
