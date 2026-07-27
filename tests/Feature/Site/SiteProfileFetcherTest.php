<?php

use App\Services\Site\SiteProfileFetcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The SSRF guard has its own tests; here we exercise the fetch itself.
    config()->set('security.ssrf.enabled', false);
});

it('fetches and parses a site', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Acme</title><meta name="theme-color" content="#0f766e"></head><body>We move food.</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
    )]);

    $site = app(SiteProfileFetcher::class)->fetch('https://acme.example');

    expect($site?->title)->toBe('Acme')
        ->and($site?->themeColor)->toBe('#0f766e')
        ->and($site?->text)->toContain('We move food.');
});

/**
 * The whole reason this is one shared fetcher: drafting the Contextbook and the
 * Brandbook in the same request must not download the same home page twice.
 */
it('downloads a given site only once per request', function () {
    Http::fake(['*' => Http::response('<html><body>Once.</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $fetcher = app(SiteProfileFetcher::class);
    $fetcher->fetch('https://acme.example');
    $fetcher->fetch('https://acme.example');

    Http::assertSentCount(1);
});

it('remembers a failure too, so one dead site costs one timeout', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $fetcher = app(SiteProfileFetcher::class);

    expect($fetcher->fetch('https://acme.example'))->toBeNull()
        ->and($fetcher->fetch('https://acme.example'))->toBeNull();

    Http::assertSentCount(1);
});

it('refuses to treat a non-HTML response as prose', function () {
    Http::fake(['*' => Http::response('%PDF-1.7 binary garbage', 200, ['Content-Type' => 'application/pdf'])]);

    expect(app(SiteProfileFetcher::class)->fetch('https://acme.example/brochure.pdf'))->toBeNull();
});

it('degrades to null instead of throwing at the caller', function () {
    Http::fake(fn () => throw new RuntimeException('connection reset'));

    expect(app(SiteProfileFetcher::class)->fetch('https://acme.example'))->toBeNull()
        ->and(app(SiteProfileFetcher::class)->fetch(null))->toBeNull()
        ->and(app(SiteProfileFetcher::class)->fetch('  '))->toBeNull();
});
