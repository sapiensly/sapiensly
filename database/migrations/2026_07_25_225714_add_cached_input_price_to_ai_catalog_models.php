<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-model cached-input price. AiPricing derived the cache-read rate from a
 * global 0.1x multiplier — Anthropic's ratio — but providers set their own:
 * xAI bills Grok's cached input at $0.30/MTok (0.15x), reconciled against a
 * live OpenRouter invoice ($0.481 real vs $0.428 recorded on a cache-heavy
 * landing build, a ~12% under-record). NULL keeps the 0.1x fallback, so only
 * models whose ratio is known to differ need a value.
 *
 * Seeds the Grok rows (the reconciled case). Anthropic models are left NULL on
 * purpose — their real ratio IS 0.1x, so the fallback is already exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_catalog_models', function (Blueprint $table) {
            $table->decimal('cached_input_price_per_mtok', 12, 6)->nullable()->after('input_price_per_mtok');
        });

        // Grok 4.5 via OpenRouter: $2/MTok input, cached at $0.30/MTok.
        DB::table('ai_catalog_models')
            ->where('driver', 'openrouter')
            ->where('model_id', '~x-ai/grok-latest')
            ->whereNull('cached_input_price_per_mtok')
            ->update(['cached_input_price_per_mtok' => 0.30, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('ai_catalog_models', function (Blueprint $table) {
            $table->dropColumn('cached_input_price_per_mtok');
        });
    }
};
