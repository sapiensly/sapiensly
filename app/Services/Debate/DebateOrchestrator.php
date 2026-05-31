<?php

namespace App\Services\Debate;

use App\Events\Debate\DebateRoundAssessed;
use App\Events\Debate\DebateRoundStarted;
use App\Events\Debate\DebateStatusChanged;
use App\Jobs\AssessDebateRoundJob;
use App\Jobs\RunDebateTurnJob;
use App\Jobs\SynthesizeDebateJob;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drives an IA Debate from opening statements through rebuttal rounds, moderator
 * consensus assessment, and final synthesis. Jobs are thin; this is the brain.
 *
 * Each round's participant turns run as a Bus::batch on the `debate` queue so
 * the models stream in parallel; the batch's `finally` callback dispatches the
 * moderator assessment, which calls back into handleAssessment() to decide
 * whether to run another rebuttal round or synthesize the conclusions.
 */
class DebateOrchestrator
{
    private const DEBATER_SYSTEM = <<<'PROMPT'
        You are %s, an expert participant in a structured, good-faith debate among several AI models. Your goal is to reach the most correct, useful conclusion — not to "win".

        Rules:
        - Write ALL of your prose — your stance and your whole argument — in the SAME LANGUAGE as the question you are given. Never switch languages.
        - Begin your reply with exactly one line: `**Position:** <your stance in a single clear sentence>`. Keep the literal marker `**Position:**` in English, but write the stance text itself in the question's language.
        - Then argue your case in concise Markdown (a few short paragraphs or bullet points, roughly 150–300 words).
        - Be intellectually honest: weigh trade-offs, acknowledge strong points from others, and change your position if the evidence and arguments warrant it. Consensus through reason is a success, not a loss.
        - Be specific and substantive. Avoid filler and empty agreement.
        PROMPT;

    private const MODERATOR_ASSESS_SYSTEM = <<<'PROMPT'
        You are a neutral, rigorous debate moderator. You judge whether the participants have reached GENUINE substantive consensus on the core question — real agreement on the answer and key reasons, not mere politeness or partial overlap.

        Respond with ONLY a single minified JSON object, no markdown, no code fences, no commentary. Use exactly this schema:
        {"consensus_reached": <true|false>, "consensus_score": <integer 0-100>, "agreements": ["..."], "disagreements": ["..."], "verdict": "<one short paragraph>", "stances": {"1": "<agree|partial|dissent>", "2": "<agree|partial|dissent>"}}

        - consensus_score: 0 = total disagreement, 100 = full agreement on the core question.
        - consensus_reached: true ONLY when the participants substantially agree on the core answer (score >= 80).
        - stances: keyed by the participant number shown to you; classify each participant's current stance relative to the emerging majority view.
        - Write the human-readable text values (agreements, disagreements, verdict) in the SAME LANGUAGE as the question. Keep the JSON keys and the stance values (agree/partial/dissent) exactly in English as specified above.
        PROMPT;

    private const SYNTHESIS_SYSTEM = <<<'PROMPT'
        You are a neutral moderator writing the final conclusions of a multi-AI debate for the user who asked the question. Be decisive, balanced, and practical.

        Write your ENTIRE response — including every section heading — in the SAME LANGUAGE as the question. Translate the section titles below into that language; never switch languages.

        Write clear Markdown with these sections in order:
        ## Recommendation
        One or two sentences with the bottom-line answer or recommended decision.
        ## Points of consensus
        Bullet list of what the participants agreed on.
        ## Open disagreements
        Bullet list of unresolved tensions and the trade-offs behind them (write "None — the council converged." if there are none).
        ## Rationale
        A short paragraph explaining the reasoning behind the recommendation.
        PROMPT;

    /** Max characters of another participant's argument fed into a rebuttal. */
    private const MAX_QUOTE_CHARS = 1800;

    /**
     * Kick off a debate: create the opening round + pending turns and dispatch
     * the first batch of participant jobs.
     */
    public function start(Debate $debate): void
    {
        $debate->forceFill([
            'status' => 'debating',
            'current_round' => 1,
            'last_activity_at' => now(),
        ])->save();

        $this->broadcastStatus($debate);

        $round = $debate->rounds()->create([
            'round_number' => 1,
            'type' => 'opening',
            'status' => 'pending',
        ]);

        foreach ($debate->participants as $participant) {
            $round->turns()->create([
                'debate_id' => $debate->id,
                'debate_participant_id' => $participant->id,
                'role' => 'participant',
                'model' => $participant->model,
                'status' => 'pending',
            ]);
        }

        $this->runRound($round);
    }

    /**
     * Dispatch all of a round's participant turns as a parallel batch; when the
     * batch finishes (success or allowed failure), assess consensus.
     */
    public function runRound(DebateRound $round): void
    {
        $round->update(['status' => 'running']);

        $this->safeBroadcast(fn () => DebateRoundStarted::dispatch($round));

        $roundId = $round->id;
        $jobs = $round->turns()
            ->where('role', 'participant')
            ->pluck('id')
            ->map(fn (string $turnId) => new RunDebateTurnJob($turnId))
            ->all();

        if (empty($jobs)) {
            AssessDebateRoundJob::dispatch($roundId);

            return;
        }

        Bus::batch($jobs)
            ->name("debate-round:{$roundId}")
            ->onQueue('debate')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($roundId) {
                AssessDebateRoundJob::dispatch($roundId);
            })
            ->dispatch();
    }

    /**
     * Persist the moderator's verdict, then either run another rebuttal round
     * or move to synthesis.
     *
     * @param  array{consensus_reached?: bool, consensus_score?: int, agreements?: array<int, string>, disagreements?: array<int, string>, verdict?: string, stances?: array<string, string>}  $result
     */
    public function handleAssessment(DebateRound $round, array $result): void
    {
        $debate = $round->debate;

        $consensusReached = (bool) ($result['consensus_reached'] ?? false);
        $score = isset($result['consensus_score'])
            ? max(0, min(100, (int) $result['consensus_score']))
            : null;

        $round->update([
            'status' => 'complete',
            'consensus_reached' => $consensusReached,
            'consensus_score' => $score,
            'consensus_summary' => [
                'agreements' => array_values(array_filter((array) ($result['agreements'] ?? []), 'is_string')),
                'disagreements' => array_values(array_filter((array) ($result['disagreements'] ?? []), 'is_string')),
                'verdict' => (string) ($result['verdict'] ?? ''),
                'stances' => (array) ($result['stances'] ?? []),
            ],
        ]);

        $debate->forceFill([
            'consensus_reached' => $consensusReached,
            'consensus_score' => $score,
            'last_activity_at' => now(),
        ])->save();

        $this->safeBroadcast(fn () => DebateRoundAssessed::dispatch($round->refresh()));

        if ($consensusReached || $debate->current_round >= $debate->max_rounds) {
            $this->startSynthesis($debate);

            return;
        }

        $next = $debate->current_round + 1;
        $debate->forceFill(['current_round' => $next])->save();
        $this->broadcastStatus($debate);

        $rebuttal = $debate->rounds()->create([
            'round_number' => $next,
            'type' => 'rebuttal',
            'status' => 'pending',
        ]);

        foreach ($debate->participants as $participant) {
            $rebuttal->turns()->create([
                'debate_id' => $debate->id,
                'debate_participant_id' => $participant->id,
                'role' => 'participant',
                'model' => $participant->model,
                'status' => 'pending',
            ]);
        }

        $this->runRound($rebuttal);
    }

    /**
     * Create the synthesis round + moderator turn and dispatch the synthesis job.
     */
    public function startSynthesis(Debate $debate): void
    {
        $debate->forceFill(['status' => 'converged', 'last_activity_at' => now()])->save();
        $this->broadcastStatus($debate);

        $roundNumber = (int) $debate->rounds()->max('round_number') + 1;

        $round = $debate->rounds()->create([
            'round_number' => $roundNumber,
            'type' => 'synthesis',
            'status' => 'running',
        ]);

        $turn = $round->turns()->create([
            'debate_id' => $debate->id,
            'debate_participant_id' => null,
            'role' => 'moderator',
            'model' => $debate->moderator_model,
            'status' => 'pending',
        ]);

        $this->safeBroadcast(fn () => DebateRoundStarted::dispatch($round));

        SynthesizeDebateJob::dispatch($turn->id);
    }

    // ---- Prompt builders -------------------------------------------------

    public function debaterInstructions(DebateParticipant $participant): string
    {
        return sprintf(self::DEBATER_SYSTEM, $participant->display_name);
    }

    public function buildTurnPrompt(DebateTurn $turn): string
    {
        $debate = $turn->debate;
        $round = $turn->round;

        if ($round->type === 'opening') {
            return "The question to deliberate:\n\n{$debate->topic}\n\nGive your opening position and the strongest argument for it.";
        }

        $participant = $turn->participant;
        $prompt = "The question to deliberate:\n\n{$debate->topic}\n\n";

        $own = $this->previousTurnContent($debate, $participant, $round);
        if ($own !== null) {
            $prompt .= "Your previous position:\n\n".Str::limit($own, self::MAX_QUOTE_CHARS)."\n\n";
        }

        $others = $this->otherParticipantsLatest($debate, $participant, $round);
        if ($others !== '') {
            $prompt .= "The other participants' latest arguments:\n\n{$others}\n";
        }

        $prompt .= "\nRespond to their strongest points: concede what is right, rebut what is wrong, refine your own view, and restate your current position.";

        return $prompt;
    }

    public function assessmentInstructions(): string
    {
        return self::MODERATOR_ASSESS_SYSTEM;
    }

    public function buildAssessmentPrompt(DebateRound $round): string
    {
        $debate = $round->debate;
        $prompt = "Question under debate:\n\n{$debate->topic}\n\nThe participants' arguments in this round:\n\n";

        foreach ($debate->participants as $participant) {
            $number = $participant->position + 1;
            $turn = $round->turns->firstWhere('debate_participant_id', $participant->id);
            $content = $turn?->content ? trim($turn->content) : '(no response)';
            $prompt .= "### Participant {$number}: {$participant->display_name}\n".Str::limit($content, self::MAX_QUOTE_CHARS)."\n\n";
        }

        $prompt .= 'Assess consensus now and reply with the JSON object only.';

        return $prompt;
    }

    public function synthesisInstructions(): string
    {
        return self::SYNTHESIS_SYSTEM;
    }

    public function buildSynthesisPrompt(Debate $debate): string
    {
        $prompt = "Question under debate:\n\n{$debate->topic}\n\nFull transcript:\n\n";

        foreach ($debate->rounds()->where('type', '!=', 'synthesis')->orderBy('round_number')->with(['turns.participant'])->get() as $round) {
            $label = ucfirst($round->type)." (round {$round->round_number})";
            $prompt .= "## {$label}\n\n";
            foreach ($round->turns as $turn) {
                if ($turn->role !== 'participant') {
                    continue;
                }
                $name = $turn->participant?->display_name ?? 'Participant';
                $content = $turn->content ? trim($turn->content) : '(no response)';
                $prompt .= "### {$name}\n".Str::limit($content, self::MAX_QUOTE_CHARS)."\n\n";
            }

            if (! empty($round->consensus_summary['verdict'] ?? null)) {
                $prompt .= "_Moderator verdict: {$round->consensus_summary['verdict']}_\n\n";
            }
        }

        $prompt .= 'Now write the final conclusions for the user.';

        return $prompt;
    }

    /**
     * The latest stance map ({participantNumber: stance}) the moderator produced,
     * used to set each participant's final_stance at synthesis time.
     *
     * @return array<string, string>
     */
    public function latestStances(Debate $debate): array
    {
        $round = $debate->rounds()
            ->where('type', '!=', 'synthesis')
            ->orderByDesc('round_number')
            ->first();

        return (array) ($round?->consensus_summary['stances'] ?? []);
    }

    // ---- Helpers ---------------------------------------------------------

    private function previousTurnContent(Debate $debate, DebateParticipant $participant, DebateRound $current): ?string
    {
        $turn = DebateTurn::query()
            ->where('debate_participant_id', $participant->id)
            ->whereHas('round', fn ($q) => $q->where('round_number', '<', $current->round_number))
            ->where('status', 'complete')
            ->orderByDesc('created_at')
            ->first();

        return $turn?->content;
    }

    private function otherParticipantsLatest(Debate $debate, DebateParticipant $self, DebateRound $current): string
    {
        $previousNumber = $current->round_number - 1;
        $previousRound = $debate->rounds->firstWhere('round_number', $previousNumber)
            ?? $debate->rounds()->where('round_number', $previousNumber)->with('turns.participant')->first();

        if ($previousRound === null) {
            return '';
        }

        $out = '';
        foreach ($previousRound->turns as $turn) {
            if ($turn->debate_participant_id === $self->id || $turn->role !== 'participant') {
                continue;
            }
            $name = $turn->participant?->display_name ?? 'Participant';
            $content = $turn->content ? trim($turn->content) : '(no response)';
            $out .= "### {$name}\n".Str::limit($content, self::MAX_QUOTE_CHARS)."\n\n";
        }

        return $out;
    }

    private function broadcastStatus(Debate $debate): void
    {
        $this->safeBroadcast(fn () => DebateStatusChanged::dispatch($debate));
    }

    private function safeBroadcast(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            Log::warning('Debate orchestrator broadcast failed (continuing)', ['error' => $e->getMessage()]);
        }
    }
}
