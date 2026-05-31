<?php

use App\Services\Security\Ssrf\DnsResolver;
use App\Services\Security\Ssrf\IpRangeMatcher;
use App\Services\Security\Ssrf\SsrfBlockedException;
use App\Services\Security\Ssrf\SsrfBlockReason;
use App\Services\Security\Ssrf\SsrfGuard;
use App\Services\Security\Ssrf\ValidatedTarget;

/**
 * Build a guard with a deterministic fake resolver so tests never touch DNS or
 * the network.
 *
 * @param  array<string, list<string>>  $map  host => resolved IPs
 */
function ssrfGuardWith(array $map = []): SsrfGuard
{
    $dns = new class($map) implements DnsResolver
    {
        /** @param array<string, list<string>> $map */
        public function __construct(private array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    };

    return new SsrfGuard($dns, new IpRangeMatcher);
}

it('blocks loopback / private / reserved / metadata IP literals', function (string $url) {
    expect(fn () => ssrfGuardWith()->inspect($url))->toThrow(SsrfBlockedException::class);
})->with([
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'imds v6 ULA' => ['http://[fd00:ec2::254]/'],
    'private 10/8' => ['http://10.0.0.5/'],
    'private 172.16/12' => ['http://172.16.0.1/'],
    'private 192.168/16' => ['http://192.168.1.1/'],
    'loopback v4' => ['http://127.0.0.1/'],
    'this-host' => ['http://0.0.0.0/'],
    'cgnat' => ['http://100.64.0.1/'],
    'loopback v6' => ['http://[::1]/'],
    'ula v6' => ['http://[fc00::1]/'],
    'link-local v6' => ['http://[fe80::1]/'],
    'v4-mapped metadata' => ['http://[::ffff:169.254.169.254]/'],
]);

it('blocks non-http(s) schemes', function (string $url) {
    expect(fn () => ssrfGuardWith()->inspect($url))->toThrow(SsrfBlockedException::class);
})->with([
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://example.test/'],
    'dict' => ['dict://example.test/'],
    'ftp' => ['ftp://example.test/'],
]);

it('blocks deceptive numeric IP encodings before DNS', function (string $url) {
    expect(fn () => ssrfGuardWith()->inspect($url))->toThrow(SsrfBlockedException::class);
})->with([
    'decimal' => ['http://2130706433/'],     // 127.0.0.1
    'hex' => ['http://0x7f000001/'],
    'octal' => ['http://017700000001/'],
    'short form' => ['http://127.1/'],
]);

it('blocks a hostname that resolves to a private IP (rebinding)', function () {
    $guard = ssrfGuardWith(['evil.test' => ['10.0.0.5']]);

    expect(fn () => $guard->inspect('http://evil.test/'))->toThrow(SsrfBlockedException::class);
});

it('blocks a host where any single A record is private', function () {
    $guard = ssrfGuardWith(['mixed.test' => ['1.2.3.4', '10.0.0.5']]);

    expect(fn () => $guard->inspect('http://mixed.test/'))->toThrow(SsrfBlockedException::class);
});

it('blocks a host that does not resolve', function () {
    expect(fn () => ssrfGuardWith()->inspect('http://nx.test/'))
        ->toThrow(SsrfBlockedException::class);
});

it('allows a public host and returns the validated target', function () {
    $guard = ssrfGuardWith(['example.test' => ['93.184.216.34']]);

    $target = $guard->inspect('http://example.test/some/path');

    expect($target)->toBeInstanceOf(ValidatedTarget::class)
        ->and($target->scheme)->toBe('http')
        ->and($target->host)->toBe('example.test')
        ->and($target->port)->toBe(80)
        ->and($target->ips)->toBe(['93.184.216.34']);
});

it('defaults https to port 443', function () {
    $guard = ssrfGuardWith(['example.test' => ['93.184.216.34']]);

    expect($guard->inspect('https://example.test/')->port)->toBe(443);
});

it('carries an enumerated reason on the exception', function () {
    try {
        ssrfGuardWith()->inspect('gopher://example.test/');
        $this->fail('expected SsrfBlockedException');
    } catch (SsrfBlockedException $e) {
        expect($e->reason)->toBe(SsrfBlockReason::Scheme);
    }
});
