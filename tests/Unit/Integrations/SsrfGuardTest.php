<?php

use App\Services\Integrations\Support\SsrfGuard;

beforeEach(function () {
    $this->guard = new SsrfGuard;
});

dataset('blocked_ips', [
    '127.0.0.1',
    '127.1.2.3',
    '10.0.0.1',
    '10.255.255.255',
    '172.16.0.1',
    '172.31.255.255',
    '192.168.1.1',
    '169.254.169.254',   // AWS metadata
    '224.0.0.1',
    '240.0.0.1',
    '0.0.0.0',
    '::1',
    'fe80::1',
    'fc00::1',
    'ff02::1',
]);

dataset('allowed_ips', [
    '1.1.1.1',
    '8.8.8.8',
    '93.184.216.34',   // example.com
    '2606:4700:4700::1111',  // cloudflare-dns v6
]);

test('blocks loopback and private IPv4/IPv6 ranges', function (string $ip) {
    expect($this->guard->isBlocked($ip))->toBeTrue();
})->with('blocked_ips');

test('allows public IPv4 and IPv6 addresses', function (string $ip) {
    expect($this->guard->isBlocked($ip))->toBeFalse();
})->with('allowed_ips');

test('assertHostAllowed throws for blocked hostnames', function () {
    $this->guard->assertHostAllowed('http://localhost/path');
})->throws(RuntimeException::class, 'blocked by the SSRF guard');

test('assertHostAllowed throws for AWS metadata IP', function () {
    $this->guard->assertHostAllowed('http://169.254.169.254/latest/meta-data');
})->throws(RuntimeException::class);

test('assertHostAllowed is a no-op when allowInternal is true', function () {
    // Should not throw even for localhost.
    $this->guard->assertHostAllowed('http://127.0.0.1:8080/health', allowInternal: true);
    expect(true)->toBeTrue();
});

test('assertHostAllowed allows unresolvable hosts (defers to HTTP layer)', function () {
    // `.invalid` is reserved per RFC 2606 and will never resolve, so this
    // exercises the "no resolution" branch safely even on dev machines that
    // wildcard-map `.test` to localhost.
    $this->guard->assertHostAllowed('https://this-host-does-not-exist-xyz-'.uniqid().'.invalid/');
    expect(true)->toBeTrue();
});

test('assertHostAllowed throws when URL has no host', function () {
    $this->guard->assertHostAllowed('not-a-url');
})->throws(RuntimeException::class, 'no host');
