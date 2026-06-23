<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the OCR file-parser engines (mistral-ocr, cloudflare-ai) as catalog rows
 * with a per-page price, and back-fills the per-request price for search-priced
 * rerankers (Cohere). Engines are reached through OpenRouter's `file-parser`
 * plugin (see OpenRouterClient::pdfPlugins), so they are catalogued under the
 * `openrouter` driver with the `ocr` capability.
 *
 * Prices are sensible DEFAULTS in USD — admins tune them in the catalog. The
 * price is set only on first insert so later admin edits survive a re-run.
 * Unpriced rows cost 0, matching AiPricing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // OCR engines, priced per parsed page.
        $engines = [
            ['model_id' => 'mistral-ocr', 'label' => 'Mistral OCR', 'price_per_page' => 0.002, 'sort_order' => 0],
            ['model_id' => 'cloudflare-ai', 'label' => 'Cloudflare AI OCR', 'price_per_page' => 0.0005, 'sort_order' => 1],
        ];

        foreach ($engines as $engine) {
            $key = ['driver' => 'openrouter', 'model_id' => $engine['model_id'], 'capability' => 'ocr'];

            if (DB::table('ai_catalog_models')->where($key)->exists()) {
                // Refresh display fields; preserve the admin-tuned price + toggle.
                DB::table('ai_catalog_models')->where($key)->update([
                    'label' => $engine['label'],
                    'sort_order' => $engine['sort_order'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('ai_catalog_models')->insert($key + [
                'label' => $engine['label'],
                'sort_order' => $engine['sort_order'],
                'is_enabled' => true,
                'price_per_page' => $engine['price_per_page'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Search-priced rerankers: Cohere bills per search/request.
        DB::table('ai_catalog_models')
            ->where('driver', 'cohere')
            ->where('capability', 'rerank')
            ->whereNull('price_per_request')
            ->update(['price_per_request' => 0.002, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('ai_catalog_models')
            ->where('driver', 'openrouter')
            ->where('capability', 'ocr')
            ->whereIn('model_id', ['mistral-ocr', 'cloudflare-ai'])
            ->delete();
    }
};
