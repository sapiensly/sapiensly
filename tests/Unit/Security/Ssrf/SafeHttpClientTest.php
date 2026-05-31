<?php

use App\Services\Security\Ssrf\DnsResolver;
use App\Services\Security\Ssrf\IpRangeMatcher;
use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Security\Ssrf\SsrfBlockedException;
use App\Services\Security\Ssrf\SsrfGuard;
use App\Services\Security\Ssrf\ValidatedTarget;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Needs the app container for config() + the Http facade.
uses(TestCase::class);

/**
 * @param  array<string, list<string>>  $map
 */
function safeClientWith(array $map): SafeHttpClient
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

    return new SafeHttpClient(new SsrfGuard($dns, new IpRangeMatcher));
}

it('pins the connection to the validated IP (CURLOPT_RESOLVE format)', function () {
    // The pin string is what curl uses to avoid re-resolving DNS — the defense
    // that closes the TOCTOU rebinding window.
    $target = new ValidatedTarget('https', 'api.example.test', 443, ['93.184.216.34']);

    expect($target->resolveDirective())->toBe('api.example.test:443:93.184.216.34')
        ->and($target->pinnedIp())->toBe('93.184.216.34');
});

it('performs a request to an allowed host', function () {
    Http::fake(['*' => Http::response('hello', 200)]);
    $client = safeClientWith(['example.test' => ['93.184.216.34']]);

    $response = $client->request('GET', 'http://example.test/');

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('hello');
});

it('refuses a request whose initial host resolves to a private IP', function () {
    Http::fake(['*' => Http::response('should not be reached', 200)]);
    $client = safeClientWith(['evil.test' => ['10.0.0.5']]);

    expect(fn () => $client->request('GET', 'http://evil.test/'))
        ->toThrow(SsrfBlockedException::class);
});

it('blocks a redirect that points at an internal IP', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
    $client = safeClientWith(['example.test' => ['93.184.216.34']]);

    expect(fn () => $client->request('GET', 'http://example.test/'))
        ->toThrow(SsrfBlockedException::class);
});

it('blocks a redirect chain that exceeds max hops', function () {
    config()->set('security.ssrf.max_redirects', 2);
    // Every hop 302s to another public host → never settles → hop cap trips.
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://example.test/next'])]);
    $client = safeClientWith(['example.test' => ['93.184.216.34']]);

    expect(fn () => $client->request('GET', 'http://example.test/'))
        ->toThrow(SsrfBlockedException::class);
});

it('bypasses the guard when disabled (kill switch)', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('ok', 200)]);
    // No DNS map needed — disabled path uses a plain client.
    $client = safeClientWith([]);

    expect($client->request('GET', 'http://anything.test/')->status())->toBe(200);
});
