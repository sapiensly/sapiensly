<?php

/**
 * The widget bundle has to be able to change.
 *
 * Its URL is unversioned — every embed snippet ever copied points at
 * `/widget/v1/widget.js` forever — so the cache window is the only thing that
 * decides when a new build reaches the sites running it. Production used to send
 * `immutable` with a one-year max-age, which tells a browser not even to ASK
 * whether a newer file exists. Under that header none of the fixes in this
 * module would have arrived at an already-embedded site, including the one that
 * makes the widget prove which visitor it is.
 */
it('never tells a browser the bundle can be kept forever', function () {
    $response = $this->get('/widget/v1/widget.js');
    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->not->toContain('immutable')
        ->and($cacheControl)->toContain('max-age=300');
});

it('keeps the edge on the same short leash as the browser', function () {
    // A CDN holding a stale bundle serves it to every embedding site at once,
    // which is worse than one visitor's browser doing it.
    $response = $this->get('/widget/v1/widget.js');

    foreach (['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $header) {
        expect($response->headers->get($header))
            ->not->toContain('max-age=31536000')
            ->and($response->headers->get($header))->toContain('max-age=300');
    }
});

/**
 * The short window only stays cheap because revalidation is a 304 of a few
 * bytes rather than a re-download of 150 KB.
 */
it('answers a revalidation with 304 instead of the whole file', function () {
    $etag = $this->get('/widget/v1/widget.js')->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $etag])
        ->get('/widget/v1/widget.js')
        ->assertStatus(304);
});

it('serves a stale copy while it fetches the new one, so the swap is not felt', function () {
    expect($this->get('/widget/v1/widget.js')->headers->get('Cache-Control'))
        ->toContain('stale-while-revalidate');
});
