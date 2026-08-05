<?php

use App\Services\Builder\BuilderAiService;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\ToolResult as StreamingToolResult;

/**
 * The builder timeline is what anyone reads to ask why a build went wrong, and
 * it was calling every rejection a success.
 *
 * The SDK's `successful` means only that the tool returned instead of throwing.
 * Builder tools report a refused or invalid request as an ordinary return
 * carrying {"ok": false} — so a patch whose path did not resolve, and a scaffold
 * declining landing intent, both logged as wins. Measured on one live turn: 34
 * propose_change calls all written as successful, 20 of which changed nothing
 * while the app sat on the same version.
 */
function toolEvent(mixed $result, bool $sdkSuccessful = true): StreamingToolResult
{
    return new StreamingToolResult(
        id: 'evt_1',
        toolResult: new ToolResult(
            id: 'call_1',
            name: 'propose_change',
            arguments: [],
            result: $result,
        ),
        successful: $sdkSuccessful,
        error: null,
        timestamp: 0,
    );
}

it('calls a rejected patch a failure', function () {
    $rejected = json_encode([
        'ok' => false,
        'errors' => [['path' => '/ops/0', 'code' => 'patch_apply_failed']],
    ]);

    expect(BuilderAiService::toolReportedSuccess(toolEvent($rejected)))->toBeFalse();
});

it('calls a refused scaffold a failure', function () {
    // The landing-intent refusal that logged as `successful: true, tool_seconds: 0`
    // and left a live turn looking like it had scaffolded twice.
    $refused = json_encode([
        'ok' => false,
        'errors' => [['code' => 'landing_intent', 'message' => 'This request reads as a LANDING…']],
    ]);

    expect(BuilderAiService::toolReportedSuccess(toolEvent($refused)))->toBeFalse();
});

it('still calls an applied patch a success', function () {
    $applied = json_encode(['ok' => true, 'op_count' => 3]);

    expect(BuilderAiService::toolReportedSuccess(toolEvent($applied)))->toBeTrue();
});

it('keeps the SDK verdict for a payload that says nothing about ok', function () {
    // read_manifest, the reference tools, anything returning plain text: no
    // `ok` key to read, so the flag must not be invented.
    expect(BuilderAiService::toolReportedSuccess(toolEvent('{"objects":[],"pages":[]}')))->toBeTrue()
        ->and(BuilderAiService::toolReportedSuccess(toolEvent('just some prose')))->toBeTrue()
        ->and(BuilderAiService::toolReportedSuccess(toolEvent('')))->toBeTrue()
        ->and(BuilderAiService::toolReportedSuccess(toolEvent(null)))->toBeTrue();
});

it('reads an ok flag that arrives already decoded', function () {
    expect(BuilderAiService::toolReportedSuccess(toolEvent(['ok' => false])))->toBeFalse()
        ->and(BuilderAiService::toolReportedSuccess(toolEvent(['ok' => true])))->toBeTrue();
});

it('never turns an SDK failure back into a success', function () {
    // A tool that threw stays failed, whatever its partial payload looked like.
    expect(BuilderAiService::toolReportedSuccess(toolEvent(json_encode(['ok' => true]), sdkSuccessful: false)))
        ->toBeFalse();
});
