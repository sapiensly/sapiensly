<?php

namespace App\Jobs;

use App\Ai\DebateAgent;
use App\Models\DebateRound;
use App\Services\AiProviderService;
use App\Services\Debate\DebateOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * The moderator's consensus check for a finished round. Runs a non-streaming
 * structured call and hands the verdict to the orchestrator, which decides
 * whether to run another rebuttal round or synthesize. Any failure is treated
 * as "no consensus reached" so the debate keeps moving.
 */
class AssessDebateRoundJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public string $roundId) {}

    public function viaQueue(): string
    {
        return 'debate';
    }

    public function handle(DebateOrchestrator $orchestrator, AiProviderService $providers): void
    {
        $round = DebateRound::query()->with(['debate.user', 'debate.participants', 'turns'])->find($this->roundId);
        if ($round === null) {
            Log::warning('AssessDebateRoundJob: round disappeared', ['round_id' => $this->roundId]);

            return;
        }

        $debate = $round->debate;
        $result = ['consensus_reached' => false, 'consensus_score' => null];

        try {
            $user = $debate->user;
            $provider = Lab::Anthropic;
            if ($user !== null) {
                $providers->applyRuntimeConfig($user);
                $provider = $providers->resolveProviderForCatalogModel($debate->moderator_model, $user) ?? Lab::Anthropic;
            }

            $agent = new DebateAgent(
                instructions: $orchestrator->assessmentInstructions(),
                messages: [],
                tools: [],
            );

            $response = $agent->prompt(
                $orchestrator->buildAssessmentPrompt($round),
                provider: $provider,
                model: $debate->moderator_model,
            );

            $parsed = self::extractJson((string) ($response->text ?? ''));
            if ($parsed !== null) {
                $result = $parsed;
            } else {
                Log::warning('AssessDebateRoundJob: could not parse moderator JSON (treating as no consensus)', [
                    'round_id' => $round->id,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('AssessDebateRoundJob: moderator call failed (treating as no consensus)', [
                'round_id' => $round->id,
                'error' => $e->getMessage(),
            ]);
        }

        $orchestrator->handleAssessment($round, $result);
    }

    /**
     * Pull the first JSON object out of a model response, tolerating code
     * fences and surrounding prose.
     *
     * @return array<string, mixed>|null
     */
    public static function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $candidate = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }
}
