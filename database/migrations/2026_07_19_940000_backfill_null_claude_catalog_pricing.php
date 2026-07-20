<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs the Claude per-MTok pricing back-fill for catalog rows that were added
 * AFTER 2026_07_02_900000 ran and so still carry a NULL input_price_per_mtok —
 * notably `claude-fable-5`, added via the admin UI / a later catalog sync. A
 * priceless row costs every run at $0, which corrupts spend reporting and lets
 * the usage slip past the org budget guard (which meters recorded cost), so the
 * Playground shows "no cost" for otherwise-successful runs.
 *
 * Prices are the same official Anthropic list values as the 2026_07_02 seed.
 * Applied only where the price is currently NULL, so any admin-tuned price
 * survives a re-run (idempotent). Other providers' unpriced models are left
 * untouched — inventing a wrong price would corrupt accounting worse than NULL.
 */
return new class extends Migration
{
    /**
     * Claude model_id => [input $/MTok, output $/MTok].
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private array $prices = [
        'claude-fable-5' => [10, 50],
        'claude-sonnet-5' => [3, 15],
        'claude-opus-4-5' => [5, 25],
        'claude-sonnet-4-5' => [3, 15],
        'claude-haiku-4-5' => [1, 5],
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
        // Non-reversible: we cannot tell which rows this back-fill set versus
        // which were priced already, so nulling them would destroy real prices.
    }
};
