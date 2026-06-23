<?php

use App\Services\OcrEngineRouter;
use App\Services\PdfProfile;
use Tests\TestCase;

// Boots the framework so config() (the heuristic thresholds) resolves; no DB needed.
uses(TestCase::class);

function scannedProfile(int $pages, float $bytesPerPage): PdfProfile
{
    return new PdfProfile(
        pages: $pages,
        textChars: 0,
        coverageRatio: 0.0,
        bytesPerPage: $bytesPerPage,
        isScanned: true,
        extractedText: '',
    );
}

beforeEach(function () {
    $this->router = new OcrEngineRouter;
});

it('honors a valid explicit engine override', function () {
    expect($this->router->select(scannedProfile(50, 50_000), 'cloudflare-ai'))->toBe('cloudflare-ai')
        ->and($this->router->select(scannedProfile(2, 500_000), 'mistral-ocr'))->toBe('mistral-ocr');
});

it('ignores the native override and falls back to the heuristic', function () {
    // native isn't a selectable ingestion engine; small doc → quality default.
    expect($this->router->select(scannedProfile(3, 60_000), 'native'))->toBe('mistral-ocr');
});

it('picks the cheap engine for high-volume simple scans', function () {
    expect($this->router->select(scannedProfile(50, 40_000)))->toBe('cloudflare-ai');
});

it('keeps the quality engine for small documents', function () {
    expect($this->router->select(scannedProfile(4, 40_000)))->toBe('mistral-ocr');
});

it('keeps the quality engine for dense/photographic pages even when high-volume', function () {
    expect($this->router->select(scannedProfile(80, 500_000)))->toBe('mistral-ocr');
});
