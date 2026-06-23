<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Converts token usage into a USD cost using the per-model prices already kept
 * in `ai_catalog_models` (input/output per million tokens, synced from the
 * providers). Cache reads/writes are billed relative to the input price
 * (Anthropic: reads ~0.1x, writes ~1.25x). Unknown/unpriced models cost 0 (we
 * still record the tokens). Pricing is global/platform config, so it uses the
 * shared Cache, not the tenant cache.
 */
class AiPricing
{
    /** Anthropic prompt-cache multipliers, relative to the input price. */
    private const CACHE_READ_MULTIPLIER = 0.1;

    private const CACHE_WRITE_MULTIPLIER = 1.25;

    private const PRICE_CACHE_TTL = 300;

    public function costFor(string $model, Usage $usage): float
    {
        $price = $this->pricesFor($model);
        if ($price === null) {
            return 0.0;
        }

        $inPerToken = $price['input'] / 1_000_000;
        $outPerToken = $price['output'] / 1_000_000;

        return ($usage->promptTokens * $inPerToken)
            + ($usage->completionTokens * $outPerToken)
            + ($usage->cacheWriteInputTokens * $inPerToken * self::CACHE_WRITE_MULTIPLIER)
            + ($usage->cacheReadInputTokens * $inPerToken * self::CACHE_READ_MULTIPLIER);
    }

    /**
     * @return array{input: float, output: float}|null
     */
    public function pricesFor(string $model): ?array
    {
        return $this->priceMap()[$model] ?? null;
    }

    /**
     * USD cost of OCR-ing a document of $pages pages with the given engine
     * (per-page priced — mistral-ocr, cloudflare-ai). Unpriced engine ⇒ 0.
     */
    public function costForPages(string $model, int $pages): float
    {
        return max(0, $pages) * ($this->pricePerPage($model) ?? 0.0);
    }

    /**
     * USD cost of reranking. Search-priced models (e.g. Cohere) bill per query;
     * token-priced models (e.g. Jina, Voyage) bill on the input tokens of the
     * query + candidate documents. Whichever the catalog declares is applied.
     */
    public function costForRerank(string $model, int $searches, int $inputTokens): float
    {
        $perRequest = $this->pricePerRequest($model);
        if ($perRequest !== null) {
            return max(0, $searches) * $perRequest;
        }

        $price = $this->pricesFor($model);

        return $price === null ? 0.0 : max(0, $inputTokens) * ($price['input'] / 1_000_000);
    }

    public function pricePerPage(string $model): ?float
    {
        return $this->unitPriceMap()['page'][$model] ?? null;
    }

    public function pricePerRequest(string $model): ?float
    {
        return $this->unitPriceMap()['request'][$model] ?? null;
    }

    /**
     * @return array<string, array{input: float, output: float}>
     */
    private function priceMap(): array
    {
        return Cache::remember('ai_pricing_map', self::PRICE_CACHE_TTL, function (): array {
            return AiCatalogModel::query()
                ->whereNotNull('input_price_per_mtok')
                ->get(['model_id', 'input_price_per_mtok', 'output_price_per_mtok'])
                ->keyBy('model_id')
                ->map(fn (AiCatalogModel $m) => [
                    'input' => (float) $m->input_price_per_mtok,
                    'output' => (float) ($m->output_price_per_mtok ?? 0),
                ])
                ->all();
        });
    }

    /**
     * Per-page and per-request prices keyed by model id.
     *
     * @return array{page: array<string, float>, request: array<string, float>}
     */
    private function unitPriceMap(): array
    {
        return Cache::remember('ai_unit_pricing_map', self::PRICE_CACHE_TTL, function (): array {
            $map = ['page' => [], 'request' => []];

            AiCatalogModel::query()
                ->where(fn ($q) => $q->whereNotNull('price_per_page')->orWhereNotNull('price_per_request'))
                ->get(['model_id', 'price_per_page', 'price_per_request'])
                ->each(function (AiCatalogModel $m) use (&$map): void {
                    if ($m->price_per_page !== null) {
                        $map['page'][$m->model_id] = (float) $m->price_per_page;
                    }
                    if ($m->price_per_request !== null) {
                        $map['request'][$m->model_id] = (float) $m->price_per_request;
                    }
                });

            return $map;
        });
    }
}
