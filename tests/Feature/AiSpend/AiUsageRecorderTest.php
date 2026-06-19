<?php

use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\SystemAiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiUsageRecorder;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Org-level AI spend, phase 1 — capture. Token usage is priced from the catalog
 * and recorded per call, tagged own (org's BYOK key) vs system (platform key).
 */
beforeEach(function () {
    AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'claude-test',
        'label' => 'Claude Test',
        'capability' => 'chat',
        'input_price_per_mtok' => 3.0,
        'output_price_per_mtok' => 15.0,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
});

function seedOwnProvider(User $user, Organization $org): void
{
    AiProvider::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'visibility' => 'organization',
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'display_name' => 'Anthropic',
        'credentials' => ['api_key' => 'sk-test'],
        'status' => 'active',
    ]);
}

it('prices usage from the catalog (input + output + cache)', function () {
    $cost = app(AiPricing::class)->costFor('claude-test', new Usage(
        promptTokens: 1_000_000,
        completionTokens: 1_000_000,
        cacheReadInputTokens: 1_000_000, // 0.1x input = 0.3
    ));

    expect(round($cost, 4))->toBe(18.3); // 3 (in) + 15 (out) + 0.3 (cache read)
});

it('costs zero for an unpriced model but still records tokens', function () {
    expect(app(AiPricing::class)->costFor('unknown-model', new Usage(promptTokens: 999)))->toBe(0.0);
});

it('records a system-source event when the org has no own provider for the driver', function () {
    $user = User::factory()->create();

    app(AiUsageRecorder::class)->record('chat', 'claude-test', $user, null, new Usage(promptTokens: 1000, completionTokens: 500));

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->source)->toBe('system')
        ->and($event->driver)->toBe('anthropic')
        ->and($event->module)->toBe('chat')
        ->and($event->input_tokens)->toBe(1000)
        ->and($event->output_tokens)->toBe(500)
        ->and($event->cost)->toBeGreaterThan(0);
});

it('records an own-source event when the org owns a provider for the driver', function () {
    $org = Organization::create(['name' => 'Acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    seedOwnProvider($user, $org);

    app(AiUsageRecorder::class)->record('builder', 'claude-test', $user, null, new Usage(promptTokens: 1000));

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->source)->toBe('own')
        ->and($event->organization_id)->toBe($org->id);
});

it('also writes a system call to the platform ledger', function () {
    $user = User::factory()->create();

    app(AiUsageRecorder::class)->record('chat', 'claude-test', $user, null, new Usage(promptTokens: 1000, completionTokens: 500));

    $ledger = SystemAiUsageEvent::query()->firstOrFail();
    expect($ledger->module)->toBe('chat')
        ->and($ledger->driver)->toBe('anthropic')
        ->and($ledger->input_tokens)->toBe(1000)
        ->and($ledger->output_tokens)->toBe(500)
        ->and($ledger->cost)->toBeGreaterThan(0);
});

it('does not write an own-source call to the platform ledger', function () {
    $org = Organization::create(['name' => 'Acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);
    seedOwnProvider($user, $org);

    app(AiUsageRecorder::class)->record('builder', 'claude-test', $user, null, new Usage(promptTokens: 1000));

    expect(SystemAiUsageEvent::query()->count())->toBe(0);
});

it('records a context-less system call in the platform ledger', function () {
    // No user, no org — the per-org meter has nothing to attribute, but the
    // platform still paid, so the call must land in the platform ledger.
    app(AiUsageRecorder::class)->record('embeddings', 'claude-test', null, null, new Usage(promptTokens: 1000));

    $ledger = SystemAiUsageEvent::query()->firstOrFail();
    expect($ledger->organization_id)->toBeNull()
        ->and($ledger->user_id)->toBeNull()
        ->and($ledger->module)->toBe('embeddings');
});

it('never throws when recording fails', function () {
    // Unknown model + null user must not raise — best-effort by design.
    app(AiUsageRecorder::class)->record('workflow', 'no-such-model', null, null, null);

    expect(AiUsageEvent::query()->count())->toBe(1);
});
