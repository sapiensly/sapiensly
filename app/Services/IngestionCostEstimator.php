<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\Ai\AiPricing;

/**
 * Estimates, BEFORE any paid work runs, the full USD cost of ingesting a document
 * into a knowledge base: OCR (per page, only for scanned PDFs) plus embedding
 * generation (per token). PDFs are profiled locally with smalot (free) to decide
 * php-vs-OCR and count pages; OCR token volume is projected from page count since
 * the text isn't extracted yet (that would cost money). The actual cost is later
 * recorded on the usage meters during processing.
 */
class IngestionCostEstimator
{
    public function __construct(
        private PdfIngestionPlanner $planner,
        private AiPricing $pricing,
        private CloudProviderService $cloudProviderService,
    ) {}

    /**
     * @return array{method: string, engine: ?string, pages: int, estimated_tokens: int, embedding_model: string, ocr_cost: float, embedding_cost: float, total_cost: float, currency: string, estimated: bool}
     */
    public function estimateForDocument(Document $document, KnowledgeBase $knowledgeBase, ?string $engineOverride = null): array
    {
        $embeddingService = EmbeddingService::forKnowledgeBase($knowledgeBase);
        $embeddingModel = $embeddingService->getModel();

        [$method, $engine, $pages, $tokens] = $this->profile($document, $knowledgeBase, $engineOverride);

        $ocrCost = $method === 'ocr' && $engine !== null
            ? $this->pricing->costForPages($engine, $pages)
            : 0.0;

        $embeddingCost = $this->embeddingCost($embeddingModel, $tokens);

        return [
            'method' => $method,
            'engine' => $engine,
            'pages' => $pages,
            'estimated_tokens' => $tokens,
            'embedding_model' => $embeddingModel,
            'ocr_cost' => round($ocrCost, 6),
            'embedding_cost' => round($embeddingCost, 6),
            'total_cost' => round($ocrCost + $embeddingCost, 6),
            'currency' => 'USD',
            'estimated' => true,
        ];
    }

    /**
     * Resolve method / engine / pages / estimated tokens for the document.
     *
     * @return array{0: string, 1: ?string, 2: int, 3: int}
     */
    private function profile(Document $document, KnowledgeBase $knowledgeBase, ?string $engineOverride): array
    {
        // Inline-authored documents carry their text directly — no parse, no OCR.
        if ($document->isInline()) {
            return ['php', null, 0, $this->tokensFromChars(mb_strlen((string) $document->body))];
        }

        // Non-PDF files extract in-process; estimate tokens from the byte size.
        if ($document->type !== DocumentType::Pdf) {
            return ['php', null, 0, $this->tokensFromChars((int) $document->file_size)];
        }

        return $this->profilePdf($document, $knowledgeBase, $engineOverride);
    }

    /**
     * @return array{0: string, 1: ?string, 2: int, 3: int}
     */
    private function profilePdf(Document $document, KnowledgeBase $knowledgeBase, ?string $engineOverride): array
    {
        if (! $document->file_path) {
            return ['php', null, 0, 0];
        }

        $storage = $this->cloudProviderService->diskForOwnerOrFallback(
            $document->organization_id,
            $document->user_id,
        );

        if (! $storage->exists($document->file_path)) {
            return ['php', null, 0, 0];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'estimate_').'.pdf';

        try {
            file_put_contents($tempFile, $storage->get($document->file_path));
            $fileSize = (int) ($document->file_size ?: filesize($tempFile));

            $override = $engineOverride ?? ($knowledgeBase->config['ocr_engine'] ?? null);
            $plan = $this->planner->plan($tempFile, $fileSize, $override);

            $tokens = $plan->usesOcr()
                ? $plan->profile->pages * (int) config('ai.ingestion.avg_tokens_per_page', 600)
                : $this->tokensFromChars($plan->profile->textChars);

            return [$plan->method, $plan->engine, $plan->profile->pages, $tokens];
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function embeddingCost(string $model, int $tokens): float
    {
        $price = $this->pricing->pricesFor($model);

        return $price === null ? 0.0 : $tokens * ($price['input'] / 1_000_000);
    }

    private function tokensFromChars(int $chars): int
    {
        return (int) ceil(max(0, $chars) / 4);
    }
}
