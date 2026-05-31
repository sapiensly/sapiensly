<?php

namespace App\Services\Security\Ssrf;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Outbound HTTP client that closes the SSRF hole. It validates the URL through
 * SsrfGuard, then PINS the connection to the validated IP via CURLOPT_RESOLVE
 * so curl never re-resolves the hostname — that re-resolution is exactly the
 * TOCTOU window a DNS-rebinding attack exploits. Redirects are followed
 * manually, each one re-validated, and the response body is size-capped.
 *
 * Every outbound call carrying a user-controlled URL must go through here.
 */
class SafeHttpClient
{
    public function __construct(private SsrfGuard $guard) {}

    /**
     * @param  array{
     *     headers?: array<string, mixed>,
     *     query?: array<string, mixed>,
     *     json?: mixed,
     *     body?: mixed,
     *     timeout?: int,
     * }  $options
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        $method = strtoupper($method);
        $timeout = (int) ($options['timeout'] ?? 30);
        $maxBytes = (int) config('security.ssrf.max_response_bytes', 5 * 1024 * 1024);
        $maxRedirects = (int) config('security.ssrf.max_redirects', 5);

        // Operational kill-switch: when disabled, behave like a plain client.
        if (! config('security.ssrf.enabled', true)) {
            return $this->plain($method, $url, $options, $timeout);
        }

        $currentUrl = $url;
        $currentMethod = $method;
        $currentOptions = $options;

        for ($hop = 0; ; $hop++) {
            $target = $this->guard->inspect($currentUrl);
            $response = $this->dispatch($currentMethod, $currentUrl, $target, $currentOptions, $timeout, $maxBytes);

            if (! $this->isRedirect($response)) {
                $this->assertWithinSize($response, $maxBytes);

                return $response;
            }

            $location = $response->header('Location');
            if ($location === null || $location === '') {
                // 3xx without a Location — nothing to follow; hand it back.
                return $response;
            }

            if ($hop >= $maxRedirects) {
                throw SsrfBlockedException::redirectBlocked('exceeded max redirects ('.$maxRedirects.')');
            }

            $currentUrl = $this->resolveLocation($currentUrl, $location);
            // 303 (and the common 301/302-on-POST pattern) downgrades to GET
            // without a body; otherwise keep method but never re-send a body to
            // a freshly-validated host beyond the first hop is fine to keep.
            if ($response->status() === 303) {
                $currentMethod = 'GET';
                unset($currentOptions['json'], $currentOptions['body']);
            }
            // Loop: the new URL is re-inspected at the top → a redirect to an
            // internal IP dies there.
        }
    }

    /**
     * Single pinned request. allow_redirects is OFF — we follow manually so
     * each hop is re-validated.
     *
     * @param  array<string, mixed>  $options
     */
    private function dispatch(string $method, string $url, ValidatedTarget $target, array $options, int $timeout, int $maxBytes): Response
    {
        // Abort the transfer the moment the download exceeds the cap. Returning
        // a non-zero value from the progress callback makes curl fail the hop.
        $progress = function ($ch, $dlTotal, $dlNow) use ($maxBytes): int {
            return $dlNow > $maxBytes ? 1 : 0;
        };

        $pending = Http::withOptions([
            'allow_redirects' => false,
            'curl' => [
                // Pin host:port to the already-validated IP so curl does NOT
                // resolve DNS again (anti-rebinding). TLS SNI / cert validation
                // still use $host, so HTTPS is unaffected.
                CURLOPT_RESOLVE => [$target->resolveDirective()],
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => $progress,
            ],
        ])->timeout($timeout);

        if (! empty($options['headers'])) {
            $pending = $pending->withHeaders($options['headers']);
        }
        if (! empty($options['query'])) {
            $pending = $pending->withQueryParameters($options['query']);
        }

        $sendOptions = [];
        if (array_key_exists('json', $options)) {
            $sendOptions['json'] = $options['json'];
        } elseif (array_key_exists('body', $options)) {
            $sendOptions = is_array($options['body'])
                ? ['json' => $options['body']]
                : ['body' => (string) $options['body']];
        }

        try {
            return $pending->send($method, $url, $sendOptions);
        } catch (ConnectionException $e) {
            // curl error 42 = aborted by our progress callback (size cap hit).
            if (str_contains($e->getMessage(), 'aborted') || str_contains($e->getMessage(), 'Callback')) {
                throw SsrfBlockedException::tooLarge('response exceeded '.$maxBytes.' bytes');
            }
            throw $e;
        }
    }

    /**
     * Passthrough used only when the guard is disabled (test environments).
     *
     * @param  array<string, mixed>  $options
     */
    private function plain(string $method, string $url, array $options, int $timeout): Response
    {
        $pending = Http::timeout($timeout);
        if (! empty($options['headers'])) {
            $pending = $pending->withHeaders($options['headers']);
        }
        if (! empty($options['query'])) {
            $pending = $pending->withQueryParameters($options['query']);
        }

        $sendOptions = [];
        if (array_key_exists('json', $options)) {
            $sendOptions['json'] = $options['json'];
        } elseif (array_key_exists('body', $options)) {
            $sendOptions = is_array($options['body'])
                ? ['json' => $options['body']]
                : ['body' => (string) $options['body']];
        }

        return $pending->send(strtoupper($method), $url, $sendOptions);
    }

    private function isRedirect(Response $response): bool
    {
        $status = $response->status();

        return $status >= 300 && $status < 400;
    }

    /** Safety net for the non-curl path (e.g. faked responses in tests). */
    private function assertWithinSize(Response $response, int $maxBytes): void
    {
        $length = $response->header('Content-Length');
        if ($length !== null && $length !== '' && (int) $length > $maxBytes) {
            throw SsrfBlockedException::tooLarge('Content-Length '.$length.' exceeds '.$maxBytes);
        }
        if (strlen($response->body()) > $maxBytes) {
            throw SsrfBlockedException::tooLarge('body exceeds '.$maxBytes.' bytes');
        }
    }

    private function resolveLocation(string $base, string $location): string
    {
        return (string) UriResolver::resolve(new Uri($base), new Uri($location));
    }
}
