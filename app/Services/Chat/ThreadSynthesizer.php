<?php

namespace App\Services\Chat;

use App\Ai\ChatAgent;
use App\Events\Chat\ChatActionProposalReady;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Chat\Actions\ActionRegistry;
use App\Services\Chat\Actions\ManualAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Closes a multi-agent (@mention) thread with a single executable action.
 *
 * Reads the user's question plus every agent's response, asks the org-default
 * model to distill them into a structured action descriptor (JSON only), and
 * persists it as an `action_proposal` message the frontend renders as an
 * ActionCard. The core invariant: a multi-agent thread must always close in a
 * proposed action — when the model produces nothing actionable, a system message
 * says so and the thread is marked `dismissed`.
 */
class ThreadSynthesizer
{
    /** Most recent messages fed to the synthesis model. */
    private const MAX_CONTEXT_MESSAGES = 20;

    /** Hard character ceiling on the assembled transcript (~32K-token guard). */
    private const MAX_TRANSCRIPT_CHARS = 120000;

    private const SYNTHESIS_SYSTEM = <<<'PROMPT'
        You are a decision synthesizer closing a multi-agent deliberation. Several AI agents, each speaking from its own data sources, have weighed in on the user's question. Your job is to distill their positions into ONE concrete, parametrized action the user can approve in a single click.

        Respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary. Use exactly this schema:
        {"action_type":"<string>","action_label":"<human-readable, max 60 chars>","summary":"<1-2 sentences>","agreed_by":["<agent name>", ...],"parameters":{"<key>":"<value>"},"rationale":"<max 120 chars>"}

        - action_type: a short snake_case verb for the action (e.g. "launch_campaign", "process_refund"). Use "manual" if the agents did not converge on a concrete, executable action.
        - action_label: the action stated as a short imperative the user will see on the button/card.
        - summary: a direct, plain-language answer to the user's question in 1-2 sentences. State the concrete recommendation — the actual number, amount or choice the user asked for — not a description of the action. This is the headline the user reads first.
        - agreed_by: the names of the agents (from the transcript) whose positions support this action.
        - parameters: the concrete inputs the action needs, as flat key/value pairs.
        - rationale: one short clause on why this is the recommended close.
        - Write the human-readable text (action_label, summary, rationale, parameter values) in the SAME LANGUAGE as the conversation. Keep the JSON keys in English.
        If the agents reached no usable recommendation, return {"action_type":"none", ...} with an explanatory rationale.
        PROMPT;

    public function __construct(
        private readonly AiProviderService $providers,
        private readonly AiDefaults $aiDefaults,
        private readonly ActionRegistry $registry,
    ) {}

    public function synthesize(Chat $chat): void
    {
        $chat->forceFill(['synthesis_status' => 'pending'])->save();

        $payload = $this->generate($chat);

        if ($payload === null) {
            $this->dismiss($chat);

            return;
        }

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => (string) $payload['action_label'],
            'status' => 'complete',
            'message_type' => 'action_proposal',
            'action_payload' => $payload,
        ]);

        $chat->forceFill([
            'synthesis_status' => 'ready',
            'last_message_at' => now(),
        ])->save();

        $this->broadcast($message, 'ready');

        Log::info('Chat synthesis: action proposal ready', [
            'chat_id' => $chat->id,
            'message_id' => $message->id,
            'action_type' => $payload['action_type'],
        ]);
    }

    /**
     * Run the synthesis model and return a validated action payload, or null when
     * the agents produced nothing actionable.
     *
     * @return array<string, mixed>|null
     */
    private function generate(Chat $chat): ?array
    {
        $transcript = $this->buildTranscript($chat);
        if (trim($transcript) === '') {
            return null;
        }

        $model = $this->aiDefaults->model('chat');
        $provider = $this->resolveProvider($model, $chat);

        try {
            $agent = new ChatAgent(instructions: self::SYNTHESIS_SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(
                Str::limit($transcript, self::MAX_TRANSCRIPT_CHARS),
                provider: $provider,
                model: $model,
            );

            return $this->parsePayload((string) ($response->text ?? ''), $chat);
        } catch (\Throwable $e) {
            Log::warning('Chat synthesis: model call failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Decode and validate the model's JSON, normalizing the action_type through
     * the registry. Returns null on unparseable / non-actionable output.
     *
     * @return array<string, mixed>|null
     */
    private function parsePayload(string $raw, Chat $chat): ?array
    {
        $json = trim($raw);
        // Strip accidental code fences.
        $json = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $json);
        // Isolate the first JSON object if the model added prose around it.
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $type = is_string($decoded['action_type'] ?? null) ? trim($decoded['action_type']) : '';
        $label = is_string($decoded['action_label'] ?? null) ? trim($decoded['action_label']) : '';

        if ($type === '' || $type === 'none' || $label === '') {
            return null;
        }

        $agreedBy = array_values(array_filter(
            (array) ($decoded['agreed_by'] ?? []),
            'is_string',
        ));

        return [
            'action_type' => $this->registry->normalizeType($type),
            'action_label' => Str::limit($label, 60, ''),
            'summary' => Str::limit((string) ($decoded['summary'] ?? ''), 600, ''),
            'agreed_by' => $agreedBy ?: $this->rosterNames($chat),
            'parameters' => is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : [],
            'rationale' => Str::limit((string) ($decoded['rationale'] ?? ''), 120, ''),
            'executable' => $this->registry->knows($type) && $type !== ManualAction::KEY,
            // Per-message lifecycle (mirrors ProposeBuildTool); the ActionCard
            // reads this so it locks independently of the chat-level status.
            'status' => 'ready',
        ];
    }

    /**
     * Close a deliberation that could not be synthesized (the synthesis job errored
     * or timed out, or an agent turn failure halted the chain before synthesis ran)
     * so the "deliberating" indicator resolves instead of spinning forever. No-op
     * once the thread already reached a terminal state, so it's safe to call from
     * both the chain's failure handler and the job's own failed() hook.
     */
    public function abort(Chat $chat): void
    {
        $chat->refresh();
        if (in_array($chat->synthesis_status, ['ready', 'dismissed'], true)) {
            return;
        }

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'system',
            'content' => 'The team could not finish deliberating. Please try again.',
            'status' => 'complete',
            'message_type' => 'text',
        ]);

        $chat->forceFill([
            'synthesis_status' => 'dismissed',
            'last_message_at' => now(),
        ])->save();

        $this->broadcast($message, 'dismissed');

        Log::warning('Chat synthesis aborted; deliberation closed without a result', ['chat_id' => $chat->id]);
    }

    /**
     * Persist the "no clear recommendation" close and mark the thread dismissed.
     */
    private function dismiss(Chat $chat): void
    {
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'system',
            'content' => 'The agents did not reach a clear recommendation. Ask a more specific question or reduce scope.',
            'status' => 'complete',
            'message_type' => 'text',
        ]);

        $chat->forceFill([
            'synthesis_status' => 'dismissed',
            'last_message_at' => now(),
        ])->save();

        $this->broadcast($message, 'dismissed');

        Log::info('Chat synthesis: no actionable output, dismissed', ['chat_id' => $chat->id]);
    }

    private function buildTranscript(Chat $chat): string
    {
        $messages = $chat->messages()
            ->where('status', 'complete')
            ->whereIn('message_type', ['text'])
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_CONTEXT_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $agentIds = $messages->pluck('agent_id')->filter()->unique()->values()->all();
        $agentNames = empty($agentIds)
            ? []
            : Agent::query()->whereIn('id', $agentIds)->pluck('name', 'id')->all();

        $lines = [];
        foreach ($messages as $m) {
            $content = trim((string) ($m->content ?? ''));
            if ($content === '') {
                continue;
            }
            $speaker = match (true) {
                $m->role === 'user' => 'USER',
                $m->agent_id !== null => '@'.($agentNames[$m->agent_id] ?? 'Agent'),
                default => 'ASSISTANT',
            };
            $lines[] = $speaker.': '.$content;
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private function rosterNames(Chat $chat): array
    {
        $agentIds = $chat->participants()->pluck('agent_id')->all();
        if (empty($agentIds)) {
            return [];
        }

        return Agent::query()->whereIn('id', $agentIds)->pluck('name')->all();
    }

    private function resolveProvider(string $model, Chat $chat): Lab
    {
        $user = $chat->user;
        if ($user === null) {
            return Lab::Anthropic;
        }

        $this->providers->applyRuntimeConfig($user);

        return $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
    }

    private function broadcast(ChatMessage $message, string $synthesisStatus): void
    {
        try {
            ChatActionProposalReady::dispatch($message, $synthesisStatus);
        } catch (\Throwable $e) {
            Log::warning('Chat synthesis broadcast failed (continuing)', ['error' => $e->getMessage()]);
        }
    }
}
