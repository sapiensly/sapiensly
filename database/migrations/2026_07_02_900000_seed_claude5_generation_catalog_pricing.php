<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fills per-MTok pricing for the Claude 5 generation and undated-alias
 * catalog rows the 2026_06_29 seed missed. Rows added to the catalog after
 * that seed (notably `claude-sonnet-5`, added via the admin UI) carried a NULL
 * `input_price_per_mtok`, so AiPricing costed all their usage at $0 —
 * corrupting spend reporting AND letting that usage bypass the org budget
 * guard (which meters recorded cost). 3.6M Anthropic tokens were metered at
 * $0 before this was caught.
 *
 * Prices are official Anthropic list prices in USD per million tokens
 * (claude-sonnet-5 has an introductory $2/$10 through 2026-08-31; the list
 * price is seeded so accounting errs on the conservative side). Applied only
 * where the price is currently NULL, so any admin-tuned price survives a
 * re-run (idempotent, mirrors 2026_06_29_185816_seed_anthropic_catalog_pricing).
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
        // Undated aliases of already-priced dated rows.
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
