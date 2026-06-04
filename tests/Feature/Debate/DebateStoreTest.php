<?php

use App\Jobs\StartDebateJob;
use App\Models\AiProvider;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// These model ids are seeded into ai_catalog_models by the catalog migration.
const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-4-20250514';

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('creates a debate with participants and dispatches the start job', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'Should we build or buy our CRM?',
            'model_ids' => [HAIKU, SONNET],
            'max_rounds' => 3,
        ])
        ->assertRedirect();

    $debate = Debate::query()->where('user_id', $this->user->id)->firstOrFail();
    expect($debate->status)->toBe('pending')
        ->and($debate->max_rounds)->toBe(3)
        ->and($debate->moderator_model)->not->toBeNull();

    $participants = DebateParticipant::query()->where('debate_id', $debate->id)->orderBy('position')->get();
    expect($participants)->toHaveCount(2)
        ->and($participants[0]->model)->toBe(HAIKU)
        ->and($participants[0]->display_name)->not->toBeEmpty()
        ->and($participants[0]->accent)->toBe('violet')
        ->and($participants[1]->accent)->toBe('emerald');

    Queue::assertPushed(StartDebateJob::class, fn ($job) => $job->debateId === $debate->id);
});

it('rejects fewer than 2 models', function () {
    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'One model only',
            'model_ids' => [HAIKU],
        ])
        ->assertSessionHasErrors('model_ids');
});

it('rejects more than 9 models', function () {
    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'Too many',
            'model_ids' => array_fill(0, 10, HAIKU),
        ])
        ->assertSessionHasErrors('model_ids');
});

it('rejects a model that is not enabled in the catalog', function () {
    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'Unknown model',
            'model_ids' => [HAIKU, 'totally-made-up-model'],
        ])
        ->assertSessionHasErrors('model_ids.1');
});

it('accepts an enabled catalog model even without a tenant key for it', function () {
    Queue::fake();

    // The tenant only has an Anthropic key, but gpt-4o is enabled in the
    // shared catalog (served by the global system key), so it is selectable.
    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'Cross-provider debate',
            'model_ids' => [HAIKU, 'gpt-4o'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $debate = Debate::query()->where('user_id', $this->user->id)->firstOrFail();
    expect(DebateParticipant::query()->where('debate_id', $debate->id)->pluck('model')->all())
        ->toContain('gpt-4o');
});
