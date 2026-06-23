<?php

namespace App\Services;

use App\Models\User;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\OpenRouterClient;
use Laravel\Ai\Responses\Data\Usage;
use RuntimeException;

/**
 * Extracts text from a stored PDF via OpenRouter's `file-parser` plugin, using
 * the OCR engine the ingestion planner chose (mistral-ocr / cloudflare-ai). The
 * authoritative parsed text is the plugin's markdown annotation, not the model's
 * chat reply. Per-page OCR cost is recorded against the tenant + platform meters
 * so a document's ingestion cost shows up in the usage dashboards.
 */
class OcrExtractionService
{
    private const INSTRUCTIONS = 'You are an OCR engine. Extract ALL text from the attached document verbatim, preserving reading order and structure (headings, lists, tables as best you can). Output only the extracted text.';

    public function __construct(
        private OpenRouterClient $openRouter,
        private AiPricing $pricing,
        private AiUsageRecorder $usageRecorder,
    ) {}

    /**
     * @param  string  $bytes  the raw PDF bytes (already read from its tenant/BYODB disk)
     * @return array{text: string, cost: float, engine: string, pages: int}
     */
    public function extract(string $bytes, string $filename, string $engine, int $pages, User $owner): array
    {
        if (! $this->openRouter->isConfiguredFor($owner)) {
            throw new RuntimeException('OCR requires an OpenRouter API key, which is not configured.');
        }

        $dataUrl = 'data:application/pdf;base64,'.base64_encode($bytes);
        $model = (string) config('ai.ingestion.ocr.model', 'openai/gpt-4o-mini');

        $response = $this->openRouter->chat($owner, $model, [
            OpenRouterClient::textBlock(self::INSTRUCTIONS),
            OpenRouterClient::fileBlock($dataUrl, $filename),
        ], ['plugins' => OpenRouterClient::pdfPlugins($engine)]);

        $text = OpenRouterClient::fileAnnotationMarkdown($response)
            ?: OpenRouterClient::text($response);

        if (trim($text) === '') {
            throw new RuntimeException(
                "OCR produced no text via {$engine} (".OpenRouterClient::failureReason($response).').'
            );
        }

        // Per-page parsing is the authoritative OCR cost; record it on the meters.
        $cost = $this->pricing->costForPages($engine, $pages);
        $this->usageRecorder->record(
            'document_ocr',
            $engine,
            $owner,
            $owner->organization_id,
            new Usage,
            cost: $cost,
        );

        return ['text' => $text, 'cost' => $cost, 'engine' => $engine, 'pages' => $pages];
    }
}
