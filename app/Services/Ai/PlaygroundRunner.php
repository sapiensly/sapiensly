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
        $handler = $this->resolveForRun($capability, $modelId);
        $this->spendGuard->assertWithinBudget($user, $user->organization_id, $handler['model']);

        $output = match ($capability) {
            'text' => ['text' => $this->generateText($handler, (string) ($input['prompt'] ?? ''), 'You are a helpful assistant. Answer the user clearly.')],
            'coding' => ['text' => $this->generateText($handler, (string) ($input['prompt'] ?? ''), 'You are an expert programming assistant. Return correct, idiomatic code with a brief explanation.')],
            'embeddings' => $this->embeddings($handler, (string) ($input['text'] ?? '')),
            'ocr_pdf' => ['text' => $this->ocrPdf($user, $handler, $this->requireFile($file))],
            'image_vision' => ['text' => $this->imageVision($user, $handler, $this->requireFile($file), (string) ($input['prompt'] ?? ''))],
            'image_generation' => ['image' => $this->generateImage($user, $handler, (string) ($input['prompt'] ?? ''))],
            'audio_recognition' => ['text' => $this->transcribe($user, $handler, $this->requireFile($file))],
            'speech_generation' => ['audio' => $this->synthesizeSpeech($handler, (string) ($input['text'] ?? ''))],
            'reranking' => $this->rerank($handler, (string) ($input['query'] ?? ''), (array) ($input['documents'] ?? [])),
            default => throw new RuntimeException("Unknown capability '{$capability}'."),
        };

        $meta = ['model' => $handler['model'], 'driver' => $handler['driver']];
        if ($this->usage !== null) {
            $meta['usage'] = $this->usage;
        }

        return $meta + $output;
    }

    /** Token usage + cost for the current run, populated by the capability methods. */
    private ?array $usage = null;

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
     * Record token usage + cost for the current run. Cost is derived from the
     * catalog's per-Mtok prices when available.
     *
     * @param  array{input_price: ?float, output_price: ?float, ...}  $handler
     */
    private function recordUsage(array $handler, ?int $prompt, ?int $completion, bool $estimated = false): void
    {
        $ip = $handler['input_price'] ?? null;
        $op = $handler['output_price'] ?? null;

        $cost = null;
        if ($ip !== null || $op !== null) {
            $cost = round((($prompt ?? 0) / 1_000_000) * (float) ($ip ?? 0)
                + (($completion ?? 0) / 1_000_000) * (float) ($op ?? 0), 6);
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
     * Pull token usage out of a direct OpenRouter chat response.
     *
     * @param  array{input_price: ?float, output_price: ?float, ...}  $handler
     * @param  array<string, mixed>  $response
     */
    private function recordOpenRouterUsage(array $handler, array $response): void
    {
        $usage = data_get($response, 'usage');
        if (! is_array($usage)) {
            return;
        }

        $this->recordUsage(
            $handler,
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    private function requireFile(?UploadedFile $file): UploadedFile
    {
        return $file ?? throw new RuntimeException('This capability requires a file upload.');
    }

    /** @param array{model: string, driver: string, provider: Lab} $handler */
    private function generateText(array $handler, string $prompt, string $system): string
    {
        if (trim($prompt) === '') {
            throw new RuntimeException('Provide a prompt.');
        }

        // OpenRouter chat is OpenAI-compatible — the SDK driver handles plain text.
        $response = (new AnonymousAgent($system, [], []))
            ->prompt($prompt, provider: $handler['provider'], model: $handler['model']);
        $this->recordUsage($handler, $response->usage->promptTokens, $response->usage->completionTokens);

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
            ->prompt('Extract all text from the attached file.', attachments: [Files\Document::fromPath($file->getRealPath())], provider: $handler['provider'], model: $handler['model']);
        $this->recordUsage($handler, $response->usage->promptTokens, $response->usage->completionTokens);

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
            ->prompt($instruction, attachments: [Files\Image::fromPath($file->getRealPath())], provider: $handler['provider'], model: $handler['model']);
        $this->recordUsage($handler, $response->usage->promptTokens, $response->usage->completionTokens);

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
        $this->recordUsage($handler, $image->usage->promptTokens, $image->usage->completionTokens);

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
        $this->recordUsage($handler, $response->usage->promptTokens, $response->usage->completionTokens);

        return (string) $response->text;
    }

    /** @param array{model: string, driver: string, provider: Lab} $handler */
    private function synthesizeSpeech(array $handler, string $text): string
    {
        if (trim($text) === '') {
            throw new RuntimeException('Provide text to synthesize.');
        }
        if ($handler['driver'] === 'openrouter') {
            throw new RuntimeException('Text-to-speech is not available through OpenRouter. Pick a direct provider (OpenAI or ElevenLabs).');
        }

        $audio = Audio::of($text)->generate($handler['provider'], $handler['model']);

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
