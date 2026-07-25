<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Converts token usage into a USD cost using the per-model prices already kept
 * in `ai_catalog_models` (input/output per million tokens, synced from the
 * providers). Cache reads bill at the model's `cached_input_price_per_mtok`
 * when the catalog declares one — providers set their own ratio (xAI bills
 * Grok's cached input at 0.15x, reconciled against a live invoice) — falling
 * back to Anthropic's 0.1x of the input price when NULL. Cache writes bill at
 * 1.25x input (an Anthropic-only concept; other providers report 0 writes).
 * Unknown/unpriced models cost 0 (we still record the tokens). Pricing is
 * global/platform config, so it uses the shared Cache, not the tenant cache.
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

        // Cache-token semantics differ by provider family and the formula MUST
        // match the gateway's mapping or cached tokens get billed twice:
        //  - Anthropic reports input_tokens EXCLUSIVE of cache reads/writes
        //    (three disjoint buckets) → every bucket is added.
        //  - The OpenAI-compatible APIs (OpenRouter, OpenAI, xAI, Groq, …)
        //    report prompt_tokens INCLUSIVE of cached_tokens (a subset) → the
        //    cached share must come OUT of the full-rate input before its
        //    discounted rate is added, or a 90%-cached build overbills ~5x
        //    (observed live: $1.47 recorded vs $0.30 on the provider invoice).
        $fullRateInput = $usage->promptTokens;
        if (($this->priceMap()[$model]['driver'] ?? null) !== 'anthropic') {
            $fullRateInput = max(0, $fullRateInput - $usage->cacheReadInputTokens);
        }

        // The model's declared cached-input price wins; the 0.1x-of-input
        // multiplier is the fallback for models whose ratio isn't in the
        // catalog (exact for Anthropic, approximate elsewhere).
        $cachedReadPerToken = ($price['cached_input'] ?? null) !== null
            ? $price['cached_input'] / 1_000_000
            : $inPerToken * self::CACHE_READ_MULTIPLIER;

        return ($fullRateInput * $inPerToken)
            + ($usage->completionTokens * $outPerToken)
            + ($usage->cacheWriteInputTokens * $inPerToken * self::CACHE_WRITE_MULTIPLIER)
            + ($usage->cacheReadInputTokens * $cachedReadPerToken);
    }

    /**
     * @return array{input: float, output: float, cached_input: ?float, driver: string}|null
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
     * @return array<string, array{input: float, output: float, cached_input: ?float, driver: string}>
     */
    private function priceMap(): array
    {
        return Cache::remember('ai_pricing_map', self::PRICE_CACHE_TTL, function (): array {
            return AiCatalogModel::query()
                ->whereNotNull('input_price_per_mtok')
                ->get(['model_id', 'driver', 'input_price_per_mtok', 'cached_input_price_per_mtok', 'output_price_per_mtok'])
                ->keyBy('model_id')
                ->map(fn (AiCatalogModel $m) => [
                    'input' => (float) $m->input_price_per_mtok,
                    'output' => (float) ($m->output_price_per_mtok ?? 0),
                    'cached_input' => $m->cached_input_price_per_mtok !== null ? (float) $m->cached_input_price_per_mtok : null,
                    'driver' => (string) $m->driver,
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
