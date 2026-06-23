<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds non-token pricing to the model catalog so OCR engines (priced per page)
 * and reranking models (some priced per search/request rather than per token)
 * can be costed alongside the existing per-million-token chat/embedding prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_catalog_models', function (Blueprint $table) {
            // OCR engines (mistral-ocr, cloudflare-ai) bill per parsed page.
            $table->decimal('price_per_page', 12, 6)->nullable()->after('output_price_per_mtok');
            // Rerank models such as Cohere bill per search/request.
            $table->decimal('price_per_request', 12, 6)->nullable()->after('price_per_page');
        });
    }

    public function down(): void
    {
        Schema::table('ai_catalog_models', function (Blueprint $table) {
            $table->dropColumn(['price_per_page', 'price_per_request']);
        });
    }
};
