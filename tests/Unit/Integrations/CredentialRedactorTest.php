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
