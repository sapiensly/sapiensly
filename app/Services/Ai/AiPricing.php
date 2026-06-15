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
}
