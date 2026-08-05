<?php

use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Http;

/**
 * The bootstrap catalogs are a seed that goes wrong the day a provider retires
 * an id. Before this command the only cures were re-saving the key or an admin
 * pressing Sync once per driver, so an install nobody touches drifted until
 * inference failed. These guard the unattended path.
 *
 * The catalog table already carries the migration-seeded bootstrap rows, so the
 * assertions here key off ids that only a fetch could have produced.
 */
beforeEach(function () {
    // Start from a known-disconnected state: whatever the test env carries in
    // config would otherwise decide which drivers the sweep visits.
    foreach (AiProviderService::SYNCABLE_DRIVERS as $driver) {
        config()->set("ai.providers.{$driver}.key", '');
    }
});

it('refreshes every connected driver and skips the ones with no key', function () {
    config()->set('ai.providers.openai.key', 'sk-openai');
    config()->set('ai.providers.groq.key', 'gsk-groq');

    Http::fake([
        'api.openai.com/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-from-the-sweep'],
            ['id' => 'text-embedding-from-the-sweep'],
        ]]),
        'api.groq.com/*' => Http::response(['data' => [['id' => 'llama-from-the-sweep']]]),
        'api.mistral.ai/*' => Http::response(['data' => [['id' => 'mistral-from-the-sweep']]]),
    ]);

    $this->artisan('ai:sync-models')->assertSuccessful();

    expect(AiCatalogModel::query()->where('driver', 'openai')->pluck('model_id')->all())
        ->toContain('gpt-from-the-sweep', 'text-embedding-from-the-sweep')
        ->and(AiCatalogModel::query()->where('driver', 'groq')->pluck('model_id')->all())
        ->toContain('llama-from-the-sweep');

    // A driver with no key has nothing to authenticate with, so it is reported
    // rather than called — a rejected request would look like a dead provider.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mistral.ai'));
});

it('reads a saved global key, not just the env one', function () {
    AiProvider::factory()->create([
        'driver' => 'openai',
        'visibility' => 'global',
        'credentials' => ['api_key' => 'sk-from-db'],
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-from-the-sweep']]])]);

    $this->artisan('ai:sync-models --driver=openai')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-from-db'));
});

it('adds new models disabled and leaves an existing toggle alone', function () {
    // The whole point of running unattended: a sweep must never turn a model on
    // for a tenant, nor turn off one an admin deliberately enabled.
    AiCatalogModel::query()->updateOrCreate(
        ['driver' => 'openai', 'model_id' => 'gpt-already-known', 'capability' => 'chat'],
        ['label' => 'stale label', 'is_enabled' => true],
    );

    config()->set('ai.providers.openai.key', 'sk-openai');
    Http::fake(['api.openai.com/*' => Http::response(['data' => [
        ['id' => 'gpt-already-known'],
        ['id' => 'gpt-brand-new'],
    ]])]);

    $this->artisan('ai:sync-models --driver=openai')->assertSuccessful();

    $existing = AiCatalogModel::query()->where('model_id', 'gpt-already-known')->sole();
    $fresh = AiCatalogModel::query()->where('model_id', 'gpt-brand-new')->sole();

    expect($existing->is_enabled)->toBeTrue()
        ->and($fresh->is_enabled)->toBeFalse();
});

it('does not erode a curated label into the bare model id', function () {
    // OpenAI's listing carries no display name, so the "label" it yields is the
    // id. Running nightly, writing that through would rename every curated row.
    AiCatalogModel::query()->updateOrCreate(
        ['driver' => 'openai', 'model_id' => 'gpt-already-known', 'capability' => 'chat'],
        ['label' => 'GPT Already Known', 'is_enabled' => true],
    );

    config()->set('ai.providers.openai.key', 'sk-openai');
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-already-known']]])]);

    $this->artisan('ai:sync-models --driver=openai')->assertSuccessful();

    expect(AiCatalogModel::query()->where('model_id', 'gpt-already-known')->sole()->label)
        ->toBe('GPT Already Known');
});

it('still adopts a real display name the provider sends', function () {
    // Anthropic does send one, and it is the reason labels refresh at all.
    AiCatalogModel::query()->updateOrCreate(
        ['driver' => 'anthropic', 'model_id' => 'claude-from-the-sweep', 'capability' => 'chat'],
        ['label' => 'stale label', 'is_enabled' => true],
    );

    config()->set('ai.providers.anthropic.key', 'sk-ant');
    Http::fake(['api.anthropic.com/*' => Http::response(['data' => [
        ['id' => 'claude-from-the-sweep', 'display_name' => 'Claude From The Sweep'],
    ]])]);

    $this->artisan('ai:sync-models --driver=anthropic')->assertSuccessful();

    expect(AiCatalogModel::query()->where('model_id', 'claude-from-the-sweep')->sole()->label)
        ->toBe('Claude From The Sweep');
});

it('keeps sweeping when one provider is unreachable, and says so', function () {
    config()->set('ai.providers.openai.key', 'sk-openai');
    config()->set('ai.providers.groq.key', 'gsk-groq');

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'nope'], 401),
        'api.groq.com/*' => Http::response(['data' => [['id' => 'llama-from-the-sweep']]]),
    ]);

    // Non-zero so a scheduled run surfaces the failure instead of reporting a
    // green sweep that refreshed nothing.
    $this->artisan('ai:sync-models')->assertFailed();

    expect(AiCatalogModel::query()->where('model_id', 'llama-from-the-sweep')->exists())->toBeTrue();
});

it('refuses a driver with no listing endpoint instead of silently doing nothing', function () {
    Http::fake();

    $this->artisan('ai:sync-models --driver=voyageai')->assertFailed();

    Http::assertNothingSent();
});
