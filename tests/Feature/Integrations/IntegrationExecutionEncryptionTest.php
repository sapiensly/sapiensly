<?php

use App\Models\IntegrationExecution;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('redacts known secrets and encrypts the blob columns at rest', function () {
    $exec = IntegrationExecution::factory()->create([
        'request_headers' => ['Authorization' => 'Bearer SECRET123', 'X-Request-Id' => 'req_1'],
        'response_headers' => ['Set-Cookie' => 'session=TOPSECRET'],
        'response_body' => '{"access_token":"TOKENSECRET","ok":true}',
        'url' => 'https://api.example.com/v1?api_key=URLSECRET&page=2',
        'error_message' => 'failed with Bearer ERRSECRET',
    ]);

    // Raw columns (no cast) must not contain the secrets — they are encrypted.
    $raw = DB::table('integration_executions')->where('id', $exec->id)->first();
    expect($raw->request_headers)->not->toContain('SECRET123')
        ->and($raw->response_headers)->not->toContain('TOPSECRET')
        ->and($raw->response_body)->not->toContain('TOKENSECRET');

    // Through the model: the redaction survived the encrypt/decrypt round-trip
    // (the secret was removed BEFORE encryption, so it's gone for good).
    $fresh = $exec->fresh();
    expect($fresh->request_headers['Authorization'])->toBe('[REDACTED]')
        ->and($fresh->request_headers['X-Request-Id'])->toBe('req_1')
        ->and($fresh->response_headers['Set-Cookie'])->toBe('[REDACTED]');

    $body = json_decode($fresh->response_body, true);
    expect($body['access_token'])->toBe('[REDACTED]')
        ->and($body['ok'])->toBeTrue();

    // url + error_message are redacted but kept readable (not encrypted).
    expect($fresh->url)->toContain('api_key=[REDACTED]')
        ->and($fresh->url)->toContain('page=2')
        ->and($fresh->url)->not->toContain('URLSECRET')
        ->and($fresh->error_message)->not->toContain('ERRSECRET')
        ->and($fresh->error_message)->toContain('[REDACTED]');
});

it('reads strictly: a plaintext row written outside the model throws', function () {
    $exec = IntegrationExecution::factory()->create();

    // Bypass the cast and write plaintext into an encrypted column.
    DB::table('integration_executions')->where('id', $exec->id)
        ->update(['request_headers' => '{"Authorization":"plain"}']);

    expect(fn () => IntegrationExecution::find($exec->id)->request_headers)
        ->toThrow(DecryptException::class);
});

it('keeps non-blob columns usable for indexed audit queries', function () {
    $exec = IntegrationExecution::factory()->create(['success' => true]);

    $found = IntegrationExecution::query()
        ->where('integration_id', $exec->integration_id)
        ->where('success', true)
        ->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($exec->id);
});
