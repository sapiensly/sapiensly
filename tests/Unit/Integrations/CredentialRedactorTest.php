<?php

use App\Services\Integrations\Support\CredentialRedactor;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->redactor = new CredentialRedactor;
});

test('redacts well-known sensitive headers case-insensitively', function () {
    $headers = [
        'Authorization' => 'Bearer secret',
        'cookie' => 'session=abc',
        'X-API-KEY' => 'xyz',
        'Content-Type' => 'application/json',
        'X-Request-Id' => 'req_1',
    ];

    $masked = $this->redactor->redactHeaders($headers);

    expect($masked['Authorization'])->toBe('[REDACTED]')
        ->and($masked['cookie'])->toBe('[REDACTED]')
        ->and($masked['X-API-KEY'])->toBe('[REDACTED]')
        ->and($masked['Content-Type'])->toBe('application/json')
        ->and($masked['X-Request-Id'])->toBe('req_1');
});

test('additional redacted headers come from config', function () {
    config(['integrations.redact_headers' => ['x-custom-secret']]);

    $masked = $this->redactor->redactHeaders(['X-Custom-Secret' => 'value', 'X-Keep' => 'ok']);

    expect($masked['X-Custom-Secret'])->toBe('[REDACTED]')
        ->and($masked['X-Keep'])->toBe('ok');
});

test('redactUrl strips embedded userinfo and known query secrets', function () {
    $url = 'https://user:p@ss@api.example.com/v1/records?api_key=abc123&page=2&token=xyz';
    $clean = $this->redactor->redactUrl($url);

    expect($clean)->not->toContain('user:p@ss')
        ->and($clean)->not->toContain('abc123')
        ->and($clean)->not->toContain('xyz')
        ->and($clean)->toContain('page=2')
        ->and($clean)->toContain('api_key=[REDACTED]')
        ->and($clean)->toContain('token=[REDACTED]');
});

test('redactUrl leaves non-sensitive URLs unchanged', function () {
    $url = 'https://api.example.com/v1/records?page=2&sort=name';
    expect($this->redactor->redactUrl($url))->toBe($url);
});

test('redacts headers whose name ends with a sensitive suffix', function () {
    $masked = $this->redactor->redactHeaders([
        'X-Refresh-Token' => 'rt',
        'My-Client-Secret' => 'cs',
        'X-Request-Id' => 'keep',
    ]);

    expect($masked['X-Refresh-Token'])->toBe('[REDACTED]')
        ->and($masked['My-Client-Secret'])->toBe('[REDACTED]')
        ->and($masked['X-Request-Id'])->toBe('keep');
});

test('redacts sensitive keys in nested JSON bodies (including inside arrays)', function () {
    $body = json_encode([
        'name' => 'Ada',
        'auth' => ['access_token' => 'SECRET', 'expires_in' => 3600],
        'items' => [['client_secret' => 'SECRET2'], ['qty' => 2]],
    ]);

    $out = json_decode($this->redactor->redactStructuredBody($body, 'application/json'), true);

    expect($out['name'])->toBe('Ada')
        ->and($out['auth']['access_token'])->toBe('[REDACTED]')
        ->and($out['auth']['expires_in'])->toBe(3600)
        ->and($out['items'][0]['client_secret'])->toBe('[REDACTED]')
        ->and($out['items'][1]['qty'])->toBe(2);
});

test('redacts sensitive fields in form-urlencoded bodies', function () {
    $out = $this->redactor->redactStructuredBody('grant_type=refresh&client_secret=abc&page=2', 'application/x-www-form-urlencoded');
    parse_str($out, $parsed);

    expect($parsed['client_secret'])->toBe('[REDACTED]')
        ->and($parsed['grant_type'])->toBe('refresh')
        ->and($parsed['page'])->toBe('2');
});

test('auto-detects JSON bodies when no content type is given', function () {
    $out = json_decode($this->redactor->redactStructuredBody('{"token":"SECRET","ok":true}'), true);
    expect($out['token'])->toBe('[REDACTED]')->and($out['ok'])->toBeTrue();
});

test('leaves unknown content-type bodies untouched (encryption covers them)', function () {
    $body = '<xml><token>SECRET</token></xml>';
    expect($this->redactor->redactStructuredBody($body, 'application/xml'))->toBe($body);
});

test('redactText scrubs bearer tokens and key=value secrets', function () {
    $out = $this->redactor->redactText('boom: Authorization=Bearer abc.def and password=hunter2');
    expect($out)->not->toContain('abc.def')
        ->and($out)->not->toContain('hunter2')
        ->and($out)->toContain('[REDACTED]');
});

test('redaction is idempotent', function () {
    $once = $this->redactor->redactStructuredBody('{"access_token":"SECRET"}', 'application/json');
    $twice = $this->redactor->redactStructuredBody($once, 'application/json');
    expect($twice)->toBe($once);

    $h1 = $this->redactor->redactHeaders(['Authorization' => 'Bearer x']);
    expect($this->redactor->redactHeaders($h1))->toBe($h1);
});
