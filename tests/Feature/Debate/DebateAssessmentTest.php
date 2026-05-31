<?php

use App\Ai\DebateAgent;
use App\Jobs\AssessDebateRoundJob;
use App\Jobs\SynthesizeDebateJob;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\Debate\DebateOrchestrator;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->debate = Debate::factory()->forUser($this->user)->debating()->create([
        'current_round' => 1,
        'max_rounds' => 3,
        'moderator_model' => 'claude-haiku-4-5-20251001',
    ]);
    foreach ([0, 1] as $i) {
        DebateParticipant::factory()->create(['debate_id' => $this->debate->id, 'position' => $i]);
    }
    $this->round = DebateRound::factory()->opening()->create(['debate_id' => $this->debate->id, 'status' => 'running']);
    DebateTurn::factory()->complete()->count(2)->create([
        'debate_id' => $this->debate->id,
        'debate_round_id' => $this->round->id,
        'role' => 'participant',
    ]);
});

it('records consensus and triggers synthesis', function () {
    Bus::fake();
    Ai::fakeAgent(DebateAgent::class, [
        '{"consensus_reached":true,"consensus_score":88,"agreements":["Build it"],"disagreements":[],"verdict":"Agreed.","stances":{"1":"agree","2":"agree"}}',
    ]);

    (new AssessDebateRoundJob($this->round->id))->handle(
        app(DebateOrchestrator::class),
        app(AiProviderService::class),
    );

    $this->round->refresh();
    expect($this->round->consensus_reached)->toBeTrue()
        ->and($this->round->consensus_score)->toBe(88)
        ->and($this->round->consensus_summary['agreements'])->toBe(['Build it']);

    Bus::assertDispatched(SynthesizeDebateJob::class);
});

it('treats malformed moderator JSON as no consensus and continues', function () {
    Bus::fake();
    Ai::fakeAgent(DebateAgent::class, ['I think they mostly agree but here is some prose, not JSON.']);

    (new AssessDebateRoundJob($this->round->id))->handle(
        app(DebateOrchestrator::class),
        app(AiProviderService::class),
    );

    expect($this->round->refresh()->consensus_reached)->toBeFalse()
        ->and(DebateRound::where('debate_id', $this->debate->id)->where('type', 'rebuttal')->exists())->toBeTrue();

    Bus::assertDispatched(SynthesizeDebateJob::class, 0);
});

it('extracts JSON wrapped in prose and fences', function () {
    expect(AssessDebateRoundJob::extractJson('```json {"consensus_score":50} ```')['consensus_score'])->toBe(50)
        ->and(AssessDebateRoundJob::extractJson('no json here'))->toBeNull();
});
