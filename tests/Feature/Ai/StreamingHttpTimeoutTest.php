<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;

/**
 * AppServiceProvider installs a global Guzzle middleware that gives streaming
 * (SSE) requests a transport-level idle timeout — so a provider that opens the
 * connection and then stalls aborts at the socket layer instead of hanging until
 * the queue worker's hard timeout. It must apply ONLY to streamed requests and
 * leave ordinary request/response HTTP (webhooks, REST tools, MCP) untouched.
 *
 * A Guzzle MockHandler is wired as the innermost handler so the real registered
 * middleware runs; getLastOptions() exposes the options it forwarded downstream.
 */
function lastOptionsFor(array $perRequestOptions): array
{
    $mock = new MockHandler([new Response(200, [], 'ok')]);

    Http::setHandler($mock)->withOptions($perRequestOptions)->get('http://provider.test/v1/chat');

    return $mock->getLastOptions();
}

it('gives streaming requests a socket-level idle timeout', function () {
    $idle = (int) config('ai.stream_idle_timeout');

    $options = lastOptionsFor(['stream' => true]);

    expect($options['read_timeout'])->toBe($idle)
        ->and($options['curl'][CURLOPT_LOW_SPEED_LIMIT])->toBe(1)
        ->and($options['curl'][CURLOPT_LOW_SPEED_TIME])->toBe($idle);
});

it('leaves non-streaming requests untouched', function () {
    $options = lastOptionsFor([]);

    // connect_timeout is Laravel's default on every request; the middleware adds
    // neither the read idle bound nor the cURL low-speed guard for non-streams.
    expect($options)->not->toHaveKey('read_timeout')
        ->and($options['curl'][CURLOPT_LOW_SPEED_TIME] ?? null)->toBeNull();
});

it('does not clobber curl options the caller already set', function () {
    $options = lastOptionsFor([
        'stream' => true,
        'curl' => [CURLOPT_TCP_KEEPALIVE => 1],
    ]);

    expect($options['curl'][CURLOPT_TCP_KEEPALIVE])->toBe(1)
        ->and($options['curl'][CURLOPT_LOW_SPEED_TIME])->toBe((int) config('ai.stream_idle_timeout'));
});

it('does not override an explicit per-request idle timeout', function () {
    $options = lastOptionsFor(['stream' => true, 'read_timeout' => 5]);

    expect($options['read_timeout'])->toBe(5);
});
