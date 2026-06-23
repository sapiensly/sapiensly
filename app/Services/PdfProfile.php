<?php

namespace App\Services;

/**
 * The result of inspecting a PDF: how many pages, how much selectable text it
 * carries, and whether it should be treated as a digital PDF (cheap PHP text
 * extraction) or a scanned one (OCR). Carries the already-extracted text so the
 * ingestion pipeline doesn't parse the file twice.
 */
class PdfProfile
{
    public function __construct(
        public readonly int $pages,
        public readonly int $textChars,
        public readonly float $coverageRatio,
        public readonly float $bytesPerPage,
        public readonly bool $isScanned,
        public readonly string $extractedText,
        public readonly bool $parseFailed = false,
    ) {}
}
