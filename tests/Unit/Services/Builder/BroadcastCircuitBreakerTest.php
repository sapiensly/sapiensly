<?php

use App\Services\Builder\BuilderAiService;
use Tests\TestCase;

// The app, but not the database: the code under test logs, and a bare unit
// test has no facade root to log through. Nothing here touches a table.
uses(TestCase::class);

/**
 * A dead broadcaster must cost a turn ONE attempt, not a dozen.
 *
 * This is not a tidiness concern. A refused connection to Reverb does not fail
 * fast — it costs about thirty seconds — and a builder turn broadcasts a dozen
 * times (each tool call, each result, and while text streams). At 30s apiece
 * that is the whole 300s budget, so every turn died before applying a single
 * patch and told the user their request was too big. It was not.
 *
 * Measured live before the fix: read_manifest took `tool_seconds: 30.0` on a
 * 56,000-character manifest AND on an empty one — identical, because the time
 * was never the manifest. `model_seconds` came in at 90.9, 120.9 and 211.6:
 * three, four and seven blocked broadcasts, and no thinking at all.
 */
/**
 * Built without the container: safeBroadcast touches no dependency, and a unit
 * test has no cache repository to hand the constructor.
 */
function broadcastService(): BuilderAiService
{
    $service = (new ReflectionClass(BuilderAiService::class))->newInstanceWithoutConstructor();
    setBroadcastsDisabled($service, false);

    return $service;
}

function callSafeBroadcast(BuilderAiService $service, Closure $dispatch): void
{
    $method = new ReflectionMethod($service, 'safeBroadcast');
    $method->invoke($service, $dispatch);
}

function setBroadcastsDisabled(BuilderAiService $service, bool $value): void
{
    $property = new ReflectionProperty($service, 'broadcastsDisabled');
    $property->setValue($service, $value);
}

it('stops broadcasting for the rest of the turn after one failure', function () {
    $service = broadcastService();

    $attempts = 0;
    $broadcast = function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('Connection refused for URI https://sapiensly.test:8080/apps/1/events');
    };

    // A turn's worth of broadcasts against a broadcaster that is down.
    foreach (range(1, 12) as $ignored) {
        callSafeBroadcast($service, $broadcast);
    }

    // One attempt, not twelve. At ~30s each that is the difference between
    // losing 30 seconds and losing the entire turn.
    expect($attempts)->toBe(1);
});

it('never lets a broadcast failure escape into the turn', function () {
    // The message is already persisted, so a page refresh recovers the reply.
    // Crashing the job over a cosmetic live update would lose the whole build.
    $service = broadcastService();

    callSafeBroadcast($service, fn () => throw new RuntimeException('boom'));
})->throwsNoExceptions();

it('keeps broadcasting while the broadcaster is healthy', function () {
    $service = broadcastService();

    $sent = 0;
    foreach (range(1, 5) as $ignored) {
        callSafeBroadcast($service, function () use (&$sent): void {
            $sent++;
        });
    }

    expect($sent)->toBe(5);
});

it('does not punish a turn for a previous turn on the same worker', function () {
    // A worker serves many turns. Reverb coming back must not stay disabled
    // because something failed twenty minutes ago — which is exactly what a
    // `static` inside the method would have done.
    $service = broadcastService();
    setBroadcastsDisabled($service, true);

    // What streamMessage does on the way in.
    setBroadcastsDisabled($service, false);

    $sent = 0;
    callSafeBroadcast($service, function () use (&$sent): void {
        $sent++;
    });

    expect($sent)->toBe(1);
});
