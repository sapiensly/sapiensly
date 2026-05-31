<?php

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;
use App\Services\Debate\DebateOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('instructs every role to answer in the language of the question', function () {
    $orchestrator = app(DebateOrchestrator::class);
    $participant = DebateParticipant::factory()->make();

    expect($orchestrator->debaterInstructions($participant))->toContain('SAME LANGUAGE as the question')
        ->and($orchestrator->assessmentInstructions())->toContain('SAME LANGUAGE as the question')
        ->and($orchestrator->synthesisInstructions())->toContain('SAME LANGUAGE as the question');
});

it('includes the topic in the turn prompt so the model can match its language', function () {
    $debate = Debate::factory()->create(['topic' => '¿Construir o comprar nuestro CRM?']);
    $participant = DebateParticipant::factory()->create(['debate_id' => $debate->id]);
    $round = DebateRound::factory()->opening()->create(['debate_id' => $debate->id]);
    $turn = DebateTurn::factory()->pending()->create([
        'debate_id' => $debate->id,
        'debate_round_id' => $round->id,
        'debate_participant_id' => $participant->id,
    ]);

    expect(app(DebateOrchestrator::class)->buildTurnPrompt($turn->load(['debate', 'round', 'participant'])))
        ->toContain('¿Construir o comprar nuestro CRM?');
});
