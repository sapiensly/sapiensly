<?php

namespace App\Services;

/**
 * Decides the optimal way to ingest a PDF: digital PDFs with a usable text layer
 * are extracted in-process (free), while scanned / image-only PDFs are routed to
 * OCR with an engine chosen by {@see OcrEngineRouter}.
 */
class PdfIngestionPlanner
{
    public function __construct(
        private PdfAnalyzer $analyzer,
        private OcrEngineRouter $engineRouter,
    ) {}

    public function plan(string $filePath, int $fileSize, ?string $engineOverride = null): IngestionPlan
    {
        $profile = $this->analyzer->analyze($filePath, $fileSize);

        if (! $profile->isScanned && $profile->textChars > 0) {
            return new IngestionPlan('php', null, $profile);
        }

        return new IngestionPlan('ocr', $this->engineRouter->select($profile, $engineOverride), $profile);
    }
}
