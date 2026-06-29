<?php

use App\Services\Ai\AiPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fills per-MTok pricing for Anthropic (Claude) catalog rows.
 *
 * Unlike OpenRouter — whose prices sync from its API ({@see
 * App\Services\AiProviderService::syncOpenRouterCatalogModels}) — Anthropic has
 * no price source, so every `ai_catalog_models` Claude row was created with a
 * NULL `input_price_per_mtok`. {@see AiPricing} skips unpriced
 * rows (`whereNotNull('input_price_per_mtok')`), so ALL Claude usage was costed
 * at $0 — corrupting spend reporting AND letting Anthropic usage slip past the
 * org budget guard (which meters recorded cost).
 *
 * Prices are official Anthropic list prices in USD per million tokens. Applied
 * only where the price is currently NULL, so any admin-tuned price survives a
 * re-run (idempotent, mirrors {@see 2026_06_22_910001_seed_ocr_engine_pricing}).
 * Both chat and vision rows of a model share one price (pricing is per model).
 */
return new class extends Migration
{
    /**
     * Claude model_id => [input $/MTok, output $/MTok].
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private array $prices = [
        // Current generation
        'claude-opus-4-8' => [5, 25],
        'claude-opus-4-7' => [5, 25],
        'claude-opus-4-6' => [5, 25],
        'claude-opus-4-5-20251101' => [5, 25],
        'claude-sonnet-4-6' => [3, 15],
        'claude-sonnet-4-5-20250929' => [3, 15],
        'claude-haiku-4-5-20251001' => [1, 5],
        // Legacy (mostly disabled, priced for historical accuracy)
        'claude-opus-4-1-20250805' => [15, 75],
        'claude-opus-4-20250514' => [15, 75],
        'claude-sonnet-4-20250514' => [3, 15],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->prices as $modelId => [$input, $output]) {
            DB::table('ai_catalog_models')
                ->where('driver', 'anthropic')
                ->where('model_id', $modelId)
                ->whereNull('input_price_per_mtok')
                ->update([
                    'input_price_per_mtok' => $input,
                    'output_price_per_mtok' => $output,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Restore the pre-migration state (Claude rows were unpriced).
        DB::table('ai_catalog_models')
            ->where('driver', 'anthropic')
            ->whereIn('model_id', array_keys($this->prices))
            ->update([
                'input_price_per_mtok' => null,
                'output_price_per_mtok' => null,
                'updated_at' => now(),
            ]);
    }
};
