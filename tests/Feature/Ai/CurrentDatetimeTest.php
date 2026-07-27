<?php

use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\CurrentDatetimeTool;
use App\Models\User;
use App\Support\CurrentDateTime;
use Carbon\Carbon;

it('payload reports the current UTC instant in the shapes a model wants', function () {
    $payload = CurrentDateTime::payload();

    expect($payload)->toHaveKeys(['utc', 'date', 'time', 'day_of_week', 'unix'])
        ->and($payload['date'])->toBe(now()->utc()->toDateString())
        ->and($payload['utc'])->toContain(now()->utc()->toDateString())
        ->and($payload['day_of_week'])->toBe(now()->utc()->format('l'));
});

it('systemLine states the current UTC date and the no-guessing rule', function () {
    $line = CurrentDateTime::systemLine();

    expect($line)->toContain(now()->utc()->toDateString())
        ->toContain('UTC')
        ->toContain('current_datetime')
        ->toContain('Never guess');
});

/**
 * Load-bearing for prompt caching, not cosmetic: systemLine() sits inside the
 * frozen prefix marked as an Anthropic cache breakpoint, and that cache is an
 * exact prefix match. A stamp carrying seconds (as this line once did) misses
 * the cache on every new turn and re-bills tools+system at full rate.
 */
it('systemLine is byte-stable within the hour so the cached prompt prefix survives', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 14:00:01', 'UTC'));
    $atStart = CurrentDateTime::systemLine();

    Carbon::setTestNow(Carbon::parse('2026-07-26 14:59:59', 'UTC'));
    $atEnd = CurrentDateTime::systemLine();

    Carbon::setTestNow(Carbon::parse('2026-07-26 15:00:00', 'UTC'));
    $nextHour = CurrentDateTime::systemLine();

    expect($atEnd)->toBe($atStart)
        ->and($nextHour)->not->toBe($atStart)
        ->and($atStart)->toContain('2026-07-26 14:00')
        ->and($nextHour)->toContain('2026-07-26 15:00');

    Carbon::setTestNow();
});

it('systemLine renders in the organization timezone and ignores an unusable one', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 14:30:00', 'UTC'));

    expect(CurrentDateTime::systemLine('America/Mexico_City'))
        ->toContain('America/Mexico_City')
        ->toContain('2026-07-26 08:00')
        ->and(CurrentDateTime::systemLine('Mars/Olympus_Mons'))->toBe(CurrentDateTime::systemLine())
        ->and(CurrentDateTime::systemLine(''))->toBe(CurrentDateTime::systemLine());

    Carbon::setTestNow();
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
