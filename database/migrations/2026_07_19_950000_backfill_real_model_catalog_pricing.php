<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fills per-MTok pricing for real (non-Anthropic) catalog models that were
 * enabled with a NULL input_price_per_mtok — which costs their usage at $0,
 * corrupting spend reporting and letting it bypass the org budget guard (the
 * same failure the 2026_07_02 Claude back-fill fixed, here for every other
 * token-priced provider).
 *
 * Prices are official provider list prices in USD, researched July 2026 (input /
 * output per 1M tokens; embeddings and token-priced rerank are input-only).
 * Applied only where the price is currently NULL, so any admin-tuned price
 * survives (idempotent). Same model_id across capability rows (e.g. a chat and a
 * vision row) is updated together.
 *
 * Deliberately NOT covered here (different billing units / columns — needs a
 * separate, unit-aware pass): audio TTS (eleven_multilingual_v2, per character),
 * STT (scribe_v1, whisper-large-v3[-turbo], per hour) and image generation
 * (imagen-3.0-generate-002, grok-2-image-1212, per image). Fictional/undefined
 * rows (gpt-5.x, claude-opus-4-8, voyage-4-nano) are left NULL on purpose.
 *
 * Confidence caveats worth a manual check before relying on them for billing:
 * OpenAI (gpt-4o-mini, text-embedding-3-*) and xAI (grok-3[-mini]) figures are
 * from secondary sources — their official pages dropped/404'd these models;
 * Gemini 2.5-pro is context-length tiered (base ≤200K tier seeded); DeepSeek
 * models are deprecating to v4-flash at the same rate.
 */
return new class extends Migration
{
    /**
     * Chat / vision models: [driver, model_id, input $/MTok, output $/MTok].
     *
     * @var list<array{0: string, 1: string, 2: float, 3: float}>
     */
    private array $tokenPrices = [
        ['cohere', 'command-r', 0.50, 1.50],
        ['cohere', 'command-r-plus', 2.50, 10.00],
        ['deepseek', 'deepseek-chat', 0.14, 0.28],
        ['deepseek', 'deepseek-reasoner', 0.14, 0.28],
        ['gemini', 'gemini-2.0-flash', 0.10, 0.40],
        ['gemini', 'gemini-2.5-pro-preview-05-06', 1.25, 10.00],
        ['groq', 'llama-3.1-8b-instant', 0.05, 0.08],
        ['groq', 'llama-3.3-70b-versatile', 0.59, 0.79],
        ['mistral', 'mistral-large-latest', 0.50, 1.50],
        ['mistral', 'mistral-small-latest', 0.15, 0.60],
        ['openai', 'gpt-4o-mini', 0.15, 0.60],
        ['xai', 'grok-3', 3.00, 15.00],
        ['xai', 'grok-3-mini', 0.25, 0.50],
    ];

    /**
     * Embeddings / token-priced rerank: [driver, model_id, input $/MTok] — no
     * output price.
     *
     * @var list<array{0: string, 1: string, 2: float}>
     */
    private array $inputOnlyPrices = [
        ['cohere', 'embed-english-v3.0', 0.10],
        ['jina', 'jina-embeddings-v3', 0.02],
        ['jina', 'jina-reranker-v2-base-multilingual', 0.02],
        ['mistral', 'mistral-embed', 0.10],
        ['openai', 'text-embedding-3-small', 0.02],
        ['openai', 'text-embedding-3-large', 0.13],
        ['voyageai', 'voyage-code-3', 0.18],
        ['voyageai', 'voyage-finance-2', 0.12],
        ['voyageai', 'voyage-law-2', 0.12],
        ['voyageai', 'rerank-2.5', 0.05],
        ['voyageai', 'rerank-2.5-lite', 0.02],
        ['voyageai', 'voyage-4', 0.06],
        ['voyageai', 'voyage-4-large', 0.12],
        ['voyageai', 'voyage-4-lite', 0.02],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->tokenPrices as [$driver, $modelId, $input, $output]) {
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

        foreach ($this->inputOnlyPrices as [$driver, $modelId, $input]) {
            DB::table('ai_catalog_models')
                ->where('driver', $driver)
                ->where('model_id', $modelId)
                ->whereNull('input_price_per_mtok')
                ->update([
                    'input_price_per_mtok' => $input,
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
