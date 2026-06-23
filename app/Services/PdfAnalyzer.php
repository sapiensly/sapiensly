<?php

namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Inspects a local PDF file with smalot/pdfparser to decide whether its text can
 * be extracted cheaply in-process (digital PDF) or whether it needs OCR (scanned
 * / image-only PDF). The extraction smalot performs here IS the digital text, so
 * a digital PDF is fully parsed in this one pass.
 */
class PdfAnalyzer
{
    /**
     * @param  int  $fileSize  the PDF size in bytes, for the bytes-per-page signal
     */
    public function analyze(string $filePath, int $fileSize): PdfProfile
    {
        $minChars = (int) config('ai.ingestion.min_chars_per_text_page', 100);
        $coverageThreshold = (float) config('ai.ingestion.scanned_coverage_threshold', 0.6);

        try {
            $pdf = (new PdfParser)->parseFile($filePath);
            $pages = $pdf->getPages();
            $pageCount = max(count($pages), 1);

            $textPages = 0;
            $allText = '';
            foreach ($pages as $page) {
                $pageText = (string) $page->getText();
                $allText .= $pageText."\n";
                if (mb_strlen(trim($pageText)) >= $minChars) {
                    $textPages++;
                }
            }

            $allText = trim($allText);
            $coverage = $textPages / $pageCount;
            $isScanned = $coverage < $coverageThreshold;

            return new PdfProfile(
                pages: $pageCount,
                textChars: mb_strlen($allText),
                coverageRatio: round($coverage, 3),
                bytesPerPage: $fileSize / $pageCount,
                isScanned: $isScanned,
                extractedText: $allText,
            );
        } catch (Throwable) {
            // A PDF smalot can't parse is almost always an image-only/scanned or
            // malformed file — route it to OCR. Estimate pages from size so cost
            // estimation still has a number to work with.
            $estimatedPages = max(1, (int) round($fileSize / 100_000));

            return new PdfProfile(
                pages: $estimatedPages,
                textChars: 0,
                coverageRatio: 0.0,
                bytesPerPage: $fileSize / $estimatedPages,
                isScanned: true,
                extractedText: '',
                parseFailed: true,
            );
        }
    }
}
