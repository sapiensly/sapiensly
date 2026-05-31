<?php

use App\Services\Builder\WireframeImporter;
use App\Services\Security\Ssrf\DnsResolver;

it('rejects a wireframe URL whose host resolves to a private IP', function () {
    // Fake DNS so a public-looking hostname resolves internally — exactly the
    // rebinding case the old assertSafeUrl (no DNS lookup) missed.
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['10.0.0.1'];
        }
    });

    $importer = app(WireframeImporter::class);

    expect(fn () => $importer->fromUrl('http://internal.test/'))
        ->toThrow(InvalidArgumentException::class);
});
