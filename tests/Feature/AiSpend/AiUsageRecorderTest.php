<?php

use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Chat;
use App\Models\Organization;
use App\Models\SystemAiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiUsageRecorder;
use Illuminate\Support\Facades\Cache;
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

it('does not double-bill cached tokens on OpenAI-compatible providers', function () {
    // OpenAI-compat semantics: prompt_tokens INCLUDES cached_tokens (a
    // subset) — unlike Anthropic, whose buckets are disjoint. Billing the
    // full prompt AND the cache line item overcharged ~5x on cache-heavy
    // builder turns (observed: $1.47 recorded vs $0.30 provider invoice).
    AiCatalogModel::create([
        'driver' => 'openrouter',
        'model_id' => '~test/orouter-model',
        'label' => 'OR Test',
        'capability' => 'chat',
        'input_price_per_mtok' => 2.0,
        'output_price_per_mtok' => 6.0,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    Cache::forget('ai_pricing_map');

    $cost = app(AiPricing::class)->costFor('~test/orouter-model', new Usage(
        promptTokens: 1_000_000,          // includes the 900k cached below
        completionTokens: 100_000,
        cacheReadInputTokens: 900_000,
    ));

    // 100k full-rate input (0.2) + 100k out (0.6) + 900k cached at 0.1x (0.18)
    expect(round($cost, 4))->toBe(0.98);

    // Anthropic keeps disjoint-bucket math: 1M in + 1M cached ≠ subset.
    $anthropic = app(AiPricing::class)->costFor('claude-test', new Usage(
        promptTokens: 1_000_000,
        completionTokens: 100_000,
        cacheReadInputTokens: 900_000,
    ));
    expect(round($anthropic, 4))->toBe(3.0 + 1.5 + 0.27); // 3 in + 1.5 out + 0.27 cache
});

it('bills cache reads at the model-declared cached-input price when the catalog has one', function () {
    // Providers set their own cached-input ratio: xAI bills Grok's cached
    // input at $0.30/MTok (0.15x), not Anthropic's 0.1x — reconciled against
    // a live OpenRouter invoice ($0.481 real vs $0.428 recorded, ~12% under).
    AiCatalogModel::create([
        'driver' => 'openrouter',
        'model_id' => '~test/grokish-model',
        'label' => 'Grokish',
        'capability' => 'chat',
        'input_price_per_mtok' => 2.0,
        'cached_input_price_per_mtok' => 0.30,
        'output_price_per_mtok' => 6.0,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    Cache::forget('ai_pricing_map');

    $cost = app(AiPricing::class)->costFor('~test/grokish-model', new Usage(
        promptTokens: 1_000_000,          // includes the 900k cached below
        completionTokens: 100_000,
        cacheReadInputTokens: 900_000,
    ));

    // 100k full-rate (0.2) + 100k out (0.6) + 900k cached at $0.30/M (0.27)
    expect(round($cost, 4))->toBe(1.07);

    // A model WITHOUT a declared cached price keeps the 0.1x-of-input fallback
    // (covered by the OpenAI-compat test above: same shape costs 0.98).
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

it('tags the app + conversation on a builder call, in both ledgers', function () {
    $user = User::factory()->create();

    app(AiUsageRecorder::class)->record(
        'builder', 'claude-test', $user, null, new Usage(promptTokens: 1000),
        appId: 'app_01buildtarget00', conversationId: 'cnv_01buildturn0001',
    );

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->app_id)->toBe('app_01buildtarget00')
        ->and($event->conversation_id)->toBe('cnv_01buildturn0001');

    // The platform ledger (system-paid) carries the same subject.
    $ledger = SystemAiUsageEvent::query()->firstOrFail();
    expect($ledger->app_id)->toBe('app_01buildtarget00')
        ->and($ledger->conversation_id)->toBe('cnv_01buildturn0001');
});

it('leaves the subject null for a call with no app (chat)', function () {
    app(AiUsageRecorder::class)->record('chat', 'claude-test', User::factory()->create(), null, new Usage(promptTokens: 10));

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->app_id)->toBeNull()
        ->and($event->conversation_id)->toBeNull();
});

it('tags the artifact a non-app call was spent on, in both ledgers', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['user_id' => $user->id, 'title' => 'Pricing questions']);

    app(AiUsageRecorder::class)->record(
        'chat', 'claude-test', $user, null, new Usage(promptTokens: 1000),
        subject: $chat,
    );

    foreach ([AiUsageEvent::query()->firstOrFail(), SystemAiUsageEvent::query()->firstOrFail()] as $row) {
        expect($row->subject_type)->toBe('chat')
            ->and($row->subject_id)->toBe($chat->id)
            // A chat is not an App, and saying it is would corrupt per-build cost.
            ->and($row->app_id)->toBeNull();
    }
});

it('records an unrecognised subject as unattributed rather than a dead slug', function () {
    app(AiUsageRecorder::class)->record(
        'chat', 'claude-test', User::factory()->create(), null, new Usage(promptTokens: 10),
        subject: Organization::create(['name' => 'Not an artifact']),
    );

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->subject_type)->toBeNull()
        ->and($event->subject_id)->toBeNull();
});
