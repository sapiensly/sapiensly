<?php

use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\CurrentDatetimeTool;
use App\Models\User;
use App\Support\CurrentDateTime;

it('payload reports the current UTC instant in the shapes a model wants', function () {
    $payload = CurrentDateTime::payload();

    expect($payload)->toHaveKeys(['utc', 'date', 'time', 'day_of_week', 'unix'])
        ->and($payload['date'])->toBe(now()->utc()->toDateString())
        ->and($payload['utc'])->toContain(now()->utc()->toDateString())
        ->and($payload['day_of_week'])->toBe(now()->utc()->format('l'));
});

it('promptLine states the current UTC date and the no-guessing rule', function () {
    $line = CurrentDateTime::promptLine();

    expect($line)->toContain(now()->utc()->toDateString())
        ->toContain('UTC')
        ->toContain('current_datetime')
        ->toContain('Never guess');
});

it('the current_datetime MCP tool returns the current UTC date', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    SapiensServer::actingAs($user)
        ->tool(CurrentDatetimeTool::class, [])
        ->assertOk()
        ->assertSee(now()->utc()->toDateString());
});

it('registers current_datetime as an undenied platform tool so it reaches every internal agent', function () {
    expect(SapiensServer::TOOLS)->toContain(CurrentDatetimeTool::class)
        ->and(PlatformToolsFactory::DENYLIST)->not->toContain('current_datetime')
        ->and(PlatformToolsFactory::CONFIRM_REQUIRED)->not->toContain('current_datetime');
});
