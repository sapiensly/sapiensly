<?php

namespace App\Services;

/**
 * How a PDF should be ingested: either cheap in-process PHP text extraction
 * ('php') or OCR via a chosen engine ('ocr'). Carries the PDF profile so callers
 * can reuse the already-extracted text (php) and the page count (cost).
 */
class IngestionPlan
{
    /**
     * @param  'php'|'ocr'  $method
     * @param  string|null  $engine  the OCR engine when $method is 'ocr', else null
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $engine,
        public readonly PdfProfile $profile,
    ) {}

    public function usesOcr(): bool
    {
        return $this->method === 'ocr';
    }
}
