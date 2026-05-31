<?php

use App\Jobs\RunDebateTurnJob;
use App\Jobs\SynthesizeDebateJob;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\User;
use App\Services\Debate\DebateOrchestrator;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function debateWithParticipants(User $user, int $count = 2, array $attrs = []): Debate
{
    $debate = Debate::factory()->forUser($user)->create($attrs);
    for ($i = 0; $i < $count; $i++) {
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'position' => $i,
            'model' => 'claude-haiku-4-5-20251001',
        ]);
    }

    return $debate->load('participants');
}

it('starts a debate with an opening round and a parallel batch of turns', function () {
    Bus::fake();
    $debate = debateWithParticipants($this->user, 3, ['status' => 'pending']);

    app(DebateOrchestrator::class)->start($debate);

    $debate->refresh();
    expect($debate->status)->toBe('debating')
        ->and($debate->current_round)->toBe(1);

    $round = $debate->rounds()->firstOrFail();
    expect($round->type)->toBe('opening')
        ->and($round->turns)->toHaveCount(3)
        ->and($round->turns->pluck('status')->unique()->all())->toBe(['pending']);

    Bus::assertBatched(fn (PendingBatch $batch) => count($batch->jobs) === 3
        && $batch->jobs->every(fn ($j) => $j instanceof RunDebateTurnJob));
});

it('synthesizes when consensus is reached', function () {
    Bus::fake();
    $debate = debateWithParticipants($this->user, 2, ['status' => 'debating', 'current_round' => 1, 'max_rounds' => 3]);
    $round = DebateRound::factory()->opening()->create(['debate_id' => $debate->id, 'status' => 'running']);

    app(DebateOrchestrator::class)->handleAssessment($round, [
        'consensus_reached' => true,
        'consensus_score' => 90,
        'agreements' => ['Build it'],
        'disagreements' => [],
        'verdict' => 'The council agrees.',
        'stances' => ['1' => 'agree', '2' => 'agree'],
    ]);

    $round->refresh();
    expect($round->consensus_reached)->toBeTrue()
        ->and($round->consensus_score)->toBe(90)
        ->and($round->status)->toBe('complete')
        ->and($debate->refresh()->consensus_reached)->toBeTrue();

    expect(DebateRound::where('debate_id', $debate->id)->where('type', 'synthesis')->exists())->toBeTrue()
        ->and(DebateRound::where('debate_id', $debate->id)->where('type', 'rebuttal')->exists())->toBeFalse();

    Bus::assertDispatched(SynthesizeDebateJob::class);
});

it('runs another rebuttal round when consensus is not reached and rounds remain', function () {
    Bus::fake();
    $debate = debateWithParticipants($this->user, 2, ['status' => 'debating', 'current_round' => 1, 'max_rounds' => 3]);
    $round = DebateRound::factory()->opening()->create(['debate_id' => $debate->id, 'status' => 'running']);

    app(DebateOrchestrator::class)->handleAssessment($round, [
        'consensus_reached' => false,
        'consensus_score' => 40,
        'agreements' => [],
        'disagreements' => ['Cost vs control'],
        'verdict' => 'Still divided.',
        'stances' => ['1' => 'partial', '2' => 'dissent'],
    ]);

    expect($debate->refresh()->current_round)->toBe(2);

    $rebuttal = DebateRound::where('debate_id', $debate->id)->where('type', 'rebuttal')->first();
    expect($rebuttal)->not->toBeNull()
        ->and($rebuttal->turns)->toHaveCount(2);

    Bus::assertDispatched(SynthesizeDebateJob::class, 0);
    Bus::assertBatched(fn (PendingBatch $batch) => count($batch->jobs) === 2);
});

it('synthesizes instead of looping forever at the round limit', function () {
    Bus::fake();
    $debate = debateWithParticipants($this->user, 2, ['status' => 'debating', 'current_round' => 3, 'max_rounds' => 3]);
    $round = DebateRound::factory()->rebuttal()->create(['debate_id' => $debate->id, 'round_number' => 3, 'status' => 'running']);

    app(DebateOrchestrator::class)->handleAssessment($round, [
        'consensus_reached' => false,
        'consensus_score' => 55,
        'verdict' => 'No agreement after the limit.',
    ]);

    expect(DebateRound::where('debate_id', $debate->id)->where('type', 'synthesis')->exists())->toBeTrue();
    Bus::assertDispatched(SynthesizeDebateJob::class);
});
