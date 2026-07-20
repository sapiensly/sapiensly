<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prices the current-generation OpenAI (gpt-5.x) and Anthropic (Opus 4.8) chat
 * models that were enabled with a NULL input_price_per_mtok. These are the live,
 * GA models — not fictional — but they post-date the earlier pricing seeds, so
 * their usage was costing $0 (corrupting spend reporting and bypassing the org
 * budget guard, the same failure the earlier back-fills fixed).
 *
 * Official list prices in USD per 1M tokens (input / output), July 2026. The
 * gpt-5.6 family is three sibling tiers (Sol > Terra > Luna). Cached-input rates
 * are ~10% of standard input across the family — already applied as a multiplier
 * by AiPricing, so only the standard rate is stored. Applied only where NULL
 * (idempotent), so admin-tuned prices survive.
 */
return new class extends Migration
{
    /**
     * [driver, model_id, input $/MTok, output $/MTok].
     *
     * @var list<array{0: string, 1: string, 2: float, 3: float}>
     */
    private array $prices = [
        ['openai', 'gpt-5.5-pro', 30.00, 180.00],
        ['openai', 'gpt-5.6-sol', 5.00, 30.00],
        ['openai', 'gpt-5.6-terra', 2.50, 15.00],
        ['openai', 'gpt-5.6-luna', 1.00, 6.00],
        ['anthropic', 'claude-opus-4-8', 5.00, 25.00],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->prices as [$driver, $modelId, $input, $output]) {
            DB::table('ai_catalog_models')
                ->where('driver', $driver)
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
        // Non-reversible: cannot distinguish rows this set from rows already
        // priced, so nulling them would destroy real prices.
    }
};
