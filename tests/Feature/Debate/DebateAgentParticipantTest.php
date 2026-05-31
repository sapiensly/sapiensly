<?php

use App\Ai\DebateAgent;
use App\Jobs\RunDebateTurnJob;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;
use App\Models\User;
use App\Services\Debate\DebateOrchestrator;
use App\Services\Debate\DebateTurnStreamer;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('lists the user agents in the debate shared props', function () {
    Agent::factory()->standalone()->general()->create(['user_id' => $this->user->id, 'name' => 'Strategist']);
    Agent::factory()->standalone()->create(); // another user

    $this->actingAs($this->user)
        ->get(route('debates.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('debate/Index')
            ->has('agents', 1)
            ->where('agents.0.name', 'Strategist'));
});

it('creates an agent participant from an agent:{id} selection', function () {
    Queue::fake();
    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'model' => 'claude-sonnet-4-20250514',
        'name' => 'Strategist',
    ]);

    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'Build or buy?',
            'model_ids' => ["agent:{$agent->id}", 'claude-haiku-4-5-20251001'],
            'max_rounds' => 2,
        ])
        ->assertRedirect();

    $debate = Debate::query()->where('user_id', $this->user->id)->firstOrFail();
    $participants = DebateParticipant::query()->where('debate_id', $debate->id)->orderBy('position')->get();

    expect($participants)->toHaveCount(2)
        ->and($participants[0]->agent_id)->toBe($agent->id)
        ->and($participants[0]->model)->toBe('claude-sonnet-4-20250514')
        ->and($participants[0]->display_name)->toBe('Strategist')
        ->and($participants[1]->agent_id)->toBeNull();
});

it('rejects an agent that does not belong to the user', function () {
    $foreign = Agent::factory()->standalone()->create();

    $this->actingAs($this->user)
        ->post(route('debates.store'), [
            'topic' => 'X',
            'model_ids' => ["agent:{$foreign->id}", 'claude-haiku-4-5-20251001'],
        ])
        ->assertSessionHasErrors('model_ids.0');
});

it('runs an agent participant turn using the agent model and persona', function () {
    Ai::fakeAgent(DebateAgent::class, ['**Position:** Build it in-house.']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'model' => 'claude-opus-4-20250514',
        'prompt_template' => 'You are a procurement strategist.',
    ]);
    $debate = Debate::factory()->forUser($this->user)->debating()->create();
    $participant = DebateParticipant::factory()->create([
        'debate_id' => $debate->id,
        'position' => 0,
        'model' => $agent->model,
        'agent_id' => $agent->id,
    ]);
    $round = DebateRound::factory()->opening()->create(['debate_id' => $debate->id, 'status' => 'running']);
    $turn = DebateTurn::factory()->pending()->create([
        'debate_id' => $debate->id,
        'debate_round_id' => $round->id,
        'debate_participant_id' => $participant->id,
        'role' => 'participant',
        'model' => $agent->model,
    ]);

    (new RunDebateTurnJob($turn->id))->handle(app(DebateTurnStreamer::class), app(DebateOrchestrator::class));

    $turn->refresh();
    expect($turn->status)->toBe('complete')
        ->and($turn->content)->toContain('Build it in-house')
        ->and($turn->model)->toBe('claude-opus-4-20250514');
});
