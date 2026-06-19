<?php

use App\Models\AiUsageEvent;
use App\Models\SystemAiUsageEvent;
use App\Services\Ai\AiUsageReport;

/**
 * Org-level AI spend, phase 1 — the dashboard read model. Shapes recorded events
 * into totals, own/system split, per-model and a daily series; the platform view
 * additionally breaks spend down by organization.
 */
function spendEvent(array $attrs = []): AiUsageEvent
{
    return AiUsageEvent::create(array_merge([
        'organization_id' => 'org_aaaaaaaaaaaa',
        'user_id' => null,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-test',
        'source' => 'system',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 1.0,
        'estimated' => false,
        'status' => 'success',
    ], $attrs));
}

it('shapes a current-scope report with totals, source split and series', function () {
    spendEvent(['source' => 'system', 'cost' => 1.0, 'model' => 'claude-a']);
    spendEvent(['source' => 'own', 'cost' => 0.5, 'model' => 'claude-b']);

    $r = app(AiUsageReport::class)->forCurrentOrg(30);

    expect($r['totals']['cost'])->toBe(1.5)
        ->and($r['by_source']['system'])->toBe(1.0)
        ->and($r['by_source']['own'])->toBe(0.5)
        ->and($r['by_model'])->toHaveCount(2)
        ->and($r['series']['labels'])->toHaveCount(30)
        ->and($r['series']['labels'])->toHaveCount(count($r['series']['system']));
});

function systemLedgerEvent(array $attrs = []): SystemAiUsageEvent
{
    return SystemAiUsageEvent::create(array_merge([
        'organization_id' => 'org_aaaaaaaaaaaa',
        'user_id' => null,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-test',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 1.0,
        'estimated' => false,
        'status' => 'success',
    ], $attrs));
}

it('breaks platform-wide spend down by organization from the system ledger', function () {
    systemLedgerEvent(['organization_id' => 'org_aaaaaaaaaaaa', 'cost' => 2.0]);
    systemLedgerEvent(['organization_id' => 'org_bbbbbbbbbbbb', 'cost' => 3.0]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect($r['totals']['cost'])->toBe(5.0)
        ->and($r['by_source']['system'])->toBe(5.0)
        ->and(collect($r['by_org'])->pluck('organization_id'))
        ->toContain('org_aaaaaaaaaaaa', 'org_bbbbbbbbbbbb');
});

it('merges own (tenant) and system (platform ledger) spend platform-wide', function () {
    // `own` BYOK spend comes from the tenant table; `system` from the ledger.
    spendEvent(['source' => 'own', 'cost' => 0.5]);
    systemLedgerEvent(['cost' => 2.0]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect($r['by_source']['own'])->toBe(0.5)
        ->and($r['by_source']['system'])->toBe(2.0)
        ->and($r['totals']['cost'])->toBe(2.5);
});

it('counts an unattributed system call in the platform-wide total', function () {
    systemLedgerEvent(['organization_id' => null, 'cost' => 1.5]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect($r['by_source']['system'])->toBe(1.5)
        ->and(collect($r['by_org'])->firstWhere('organization_id', null)['cost'])->toBe(1.5);
});
