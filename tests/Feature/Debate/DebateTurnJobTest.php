<?php

use App\Ai\DebateAgent;
use App\Events\Debate\DebateTurnChunk;
use App\Events\Debate\DebateTurnComplete;
use App\Jobs\RunDebateTurnJob;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;
use App\Models\User;
use App\Services\Debate\DebateOrchestrator;
use App\Services\Debate\DebateTurnStreamer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->debate = Debate::factory()->forUser($this->user)->debating()->create();
    $this->participant = DebateParticipant::factory()->create([
        'debate_id' => $this->debate->id,
        'position' => 0,
        'model' => 'claude-haiku-4-5-20251001',
    ]);
    $this->round = DebateRound::factory()->opening()->create(['debate_id' => $this->debate->id, 'status' => 'running']);
});

function pendingTurn($test): DebateTurn
{
    return DebateTurn::factory()->pending()->create([
        'debate_id' => $test->debate->id,
        'debate_round_id' => $test->round->id,
        'debate_participant_id' => $test->participant->id,
        'role' => 'participant',
        'model' => $test->participant->model,
    ]);
}

it('streams a turn, parses the stance, and broadcasts', function () {
    Event::fake([DebateTurnChunk::class, DebateTurnComplete::class]);
    Ai::fakeAgent(DebateAgent::class, ["**Position:** Build it in-house.\n\nWe keep full control and the long-term cost is lower."]);

    $turn = pendingTurn($this);

    (new RunDebateTurnJob($turn->id))->handle(app(DebateTurnStreamer::class), app(DebateOrchestrator::class));

    $turn->refresh();
    expect($turn->status)->toBe('complete')
        ->and($turn->content)->toContain('Build it in-house')
        ->and($turn->stance_summary)->toBe('Build it in-house.');

    Event::assertDispatched(DebateTurnChunk::class);
    Event::assertDispatched(DebateTurnComplete::class);
});

it('halts cooperatively when the stop flag is set', function () {
    Ai::fakeAgent(DebateAgent::class, ['This should never be written because we stop first.']);
    Cache::put(DebateTurnStreamer::STOP_CACHE_PREFIX.$this->debate->id, true, now()->addMinutes(5));

    $turn = pendingTurn($this);

    (new RunDebateTurnJob($turn->id))->handle(app(DebateTurnStreamer::class), app(DebateOrchestrator::class));

    $turn->refresh();
    expect($turn->status)->toBe('complete')
        ->and($turn->content ?? '')->toBe('');
});

it('marks the turn errored when the job fails', function () {
    $turn = DebateTurn::factory()->streaming()->create([
        'debate_id' => $this->debate->id,
        'debate_round_id' => $this->round->id,
        'debate_participant_id' => $this->participant->id,
        'status' => 'streaming',
    ]);

    (new RunDebateTurnJob($turn->id))->failed(new RuntimeException('boom'));

    expect($turn->refresh()->status)->toBe('error')
        ->and($turn->error)->toContain('boom');
});

it('parses a stance with no Position prefix from the first line', function () {
    expect(DebateTurnStreamer::parseStance("We should buy.\n\nReasons follow."))->toBe('We should buy.')
        ->and(DebateTurnStreamer::parseStance(''))->toBeNull();
});

it('parses a localized stance marker', function () {
    expect(DebateTurnStreamer::parseStance("**Postura:** Construir en casa.\n\nRazones…"))->toBe('Construir en casa.');
});
