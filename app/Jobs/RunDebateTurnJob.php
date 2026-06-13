<?php

namespace App\Jobs;

use App\Events\Debate\DebateTurnError;
use App\Models\Agent;
use App\Models\DebateTurn;
use App\Services\Debate\DebateOrchestrator;
use App\Services\Debate\DebateTurnStreamer;
use App\Services\RetrievalService;
use App\Services\ToolBuilderService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Streams one participant's argument for a round. Dispatched as part of a
 * Bus::batch so all participants of a round run in parallel on the `debate`
 * queue.
 */
class RunDebateTurnJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $turnId) {}

    public function viaQueue(): string
    {
        return 'debate';
    }

    public function handle(DebateTurnStreamer $streamer, DebateOrchestrator $orchestrator): void
    {
        $turn = DebateTurn::query()->with(['debate.user', 'round', 'participant'])->find($this->turnId);
        if ($turn === null) {
            Log::warning('RunDebateTurnJob: turn disappeared', ['turn_id' => $this->turnId]);

            return;
        }

        if ($turn->participant === null) {
            Log::warning('RunDebateTurnJob: participant turn has no participant', ['turn_id' => $this->turnId]);

            return;
        }

        $participant = $turn->participant;
        $instructions = $orchestrator->debaterInstructions($participant);
        $prompt = $orchestrator->buildTurnPrompt($turn);
        $model = $turn->model ?? $participant->model;
        $tools = [];

        // When the participant is backed by an agent, it debates AS that agent:
        // its persona/prompt, its knowledge bases (RAG) and its tools.
        $agent = $participant->agent_id !== null ? Agent::find($participant->agent_id) : null;
        if ($agent !== null) {
            if (trim((string) $agent->prompt_template) !== '') {
                $instructions .= "\n\n## Your expertise and guidelines\n".$agent->prompt_template;
            }
            $instructions .= $this->agentKnowledgeContext($agent, $turn->debate->topic);
            $tools = app(ToolBuilderService::class)->buildTools(
                $agent->tools()->where('status', 'active')->get()
            );
        }

        $streamer->stream($turn, $instructions, $prompt, $model, $tools);
    }

    /**
     * Best-effort RAG block from the agent's knowledge bases for the debate
     * topic. Returns '' when there are no KBs, nothing matches, or it fails.
     */
    private function agentKnowledgeContext(Agent $agent, string $topic): string
    {
        $kbIds = $agent->knowledgeBaseIds();
        if (empty($kbIds) || trim($topic) === '') {
            return '';
        }

        try {
            $result = app(RetrievalService::class)->retrieve($topic, $kbIds, topK: 6, threshold: 0.5);
            if (($result['chunk_count'] ?? 0) === 0 || trim($result['context'] ?? '') === '') {
                return '';
            }

            return "\n\n## Relevant context from your knowledge base\n"
                .'Use the following retrieved information where it helps your argument.'
                ."\n\n".$result['context'];
        } catch (Throwable $e) {
            Log::warning('RunDebateTurnJob: agent RAG retrieval failed (continuing)', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public function failed(?Throwable $e): void
    {
        $turn = DebateTurn::query()->find($this->turnId);
        if ($turn === null || ! in_array($turn->status, ['streaming', 'pending'], true)) {
            return;
        }

        $reason = $e?->getMessage() ?? 'This turn did not finish in time.';
        $turn->status = 'error';
        $turn->error = $reason;
        $turn->save();

        try {
            broadcast(new DebateTurnError($turn->debate_id, $turn->id, $reason));
        } catch (Throwable) {
            // swallow
        }
    }
}
