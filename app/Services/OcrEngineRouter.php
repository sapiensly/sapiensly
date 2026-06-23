<?php

namespace App\Services;

use App\Services\Ai\OpenRouterClient;

/**
 * Picks the OCR engine for a scanned PDF. An explicit per-KB choice always wins;
 * otherwise an automatic heuristic balances cost against quality:
 *
 *  - The default is the higher-quality engine (mistral-ocr) — best for tables,
 *    multi-column and dense/photographic pages where layout fidelity matters.
 *  - The cheap engine (cloudflare-ai) is chosen only for HIGH-VOLUME, SIMPLE
 *    scans — many pages with a low bytes-per-page footprint (plain text/fax-grade
 *    scans) — where the per-page saving is large and the quality risk is low.
 */
class OcrEngineRouter
{
    public function select(PdfProfile $profile, ?string $override = null): string
    {
        $default = (string) config('ai.ingestion.ocr.default_engine', 'mistral-ocr');
        $cheap = (string) config('ai.ingestion.ocr.cheap_engine', 'cloudflare-ai');

        // Explicit, valid per-KB override wins.
        if ($override !== null && in_array($override, OpenRouterClient::PDF_ENGINES, true) && $override !== 'native') {
            return $override;
        }

        $bulkPages = (int) config('ai.ingestion.ocr.bulk_pages', 30);
        $simpleBytesPerPage = (int) config('ai.ingestion.ocr.simple_bytes_per_page', 120_000);

        $isHighVolume = $profile->pages >= $bulkPages;
        $isSimpleScan = $profile->bytesPerPage <= $simpleBytesPerPage;

        return ($isHighVolume && $isSimpleScan) ? $cheap : $default;
    }
}
