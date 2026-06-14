<?php

namespace App\Services\Capabilities\PostCall;

use App\Ai\ChatAgent;
use App\Models\CrmUpdateProposal;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Capabilities\PostCall\Contracts\CrmConnector;
use App\Services\Capabilities\PostCall\Data\CallContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Propose sub-capability of #0001: read a CallContext and draft the CRM update it
 * implies. **No side effects on the system of record** — producing a proposal is
 * not an effect (Rule 2). The proposal is persisted as a pending tenant row; the
 * `from` side of each change is filled from the CRM's current values (never
 * hallucinated). On unusable model output it persists an explicit, zero-confidence
 * "insufficient signal" proposal rather than crashing or writing.
 */
class DraftCrmUpdate
{
    public const CAPABILITY_ID = 'cap_0001_hubspot_post_call_agent';

    private const CONFIDENCE_THRESHOLD = 0.5;

    private const SYSTEM = <<<'PROMPT'
        You turn a sales call into the single CRM update it implies. Respond with ONLY a minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"target":{"object_type":"contact|deal|note|task","object_id":"<id or null>"},"operation":"create|update","changes":[{"field":"<crm field>","to":"<new value>"}],"rationale":"<max 200 chars>","confidence":<0..1>,"evidence":[{"quote":"<short transcript excerpt>"}]}

        - object_id null means create a new object.
        - changes: only the fields that should change; do not invent the previous value.
        - rationale and evidence text in the SAME LANGUAGE as the call. Keep JSON keys in English.
        - If the call gives no usable signal for a CRM update, return {"operation":"none","confidence":0,...} with an explanatory rationale.
        PROMPT;

    public function __construct(
        private readonly CrmConnector $connector,
        private readonly AiProviderService $providers,
        private readonly AiDefaults $aiDefaults,
    ) {}

    public function draft(CallContext $call, User $user): CrmUpdateProposal
    {
        $payload = $this->generate($call, $user);

        return CrmUpdateProposal::create([
            'capability_id' => self::CAPABILITY_ID,
            'call_id' => $call->callId,
            'status' => 'pending',
            'target' => $payload['target'],
            'operation' => $payload['operation'],
            'changes' => $payload['changes'],
            'rationale' => $payload['rationale'],
            'confidence' => $payload['confidence'],
            'evidence' => $payload['evidence'],
            'call_snapshot' => $call->toArray(),
            'source_fetched_at' => $call->sourceFetchedAt,
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Whether a proposal cleared the confidence bar to be worth surfacing as
     * actionable (it is still never auto-applied — the gate is always manual).
     */
    public static function isActionable(CrmUpdateProposal $proposal): bool
    {
        return $proposal->operation !== null
            && $proposal->operation !== 'none'
            && (float) $proposal->confidence >= self::CONFIDENCE_THRESHOLD
            && ! empty($proposal->changes);
    }

    /**
     * @return array{target: array<string,mixed>|null, operation: ?string, changes: array<int,mixed>, rationale: string, confidence: float, evidence: array<int,mixed>}
     */
    private function generate(CallContext $call, User $user): array
    {
        $insufficient = [
            'target' => null,
            'operation' => 'none',
            'changes' => [],
            'rationale' => 'The call did not provide a usable signal for a CRM update.',
            'confidence' => 0.0,
            'evidence' => [],
        ];

        $model = $this->aiDefaults->model('chat');
        $provider = $this->resolveProvider($model, $user);

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt($this->buildPrompt($call), provider: $provider, model: $model);
            $payload = $this->parse((string) ($response->text ?? ''));
        } catch (\Throwable $e) {
            Log::warning('Capability 0001: draft model call failed', [
                'call_id' => $call->callId,
                'error' => $e->getMessage(),
            ]);

            return $insufficient;
        }

        if ($payload === null || ($payload['operation'] ?? 'none') === 'none') {
            return $insufficient;
        }

        // Fill the `from` side from the CRM's real current values for an update.
        $target = $payload['target'] ?? [];
        if (($payload['operation'] ?? null) === 'update' && ! empty($target['object_id'])) {
            $current = $this->connector->currentFields(
                (string) ($target['object_type'] ?? ''),
                (string) $target['object_id'],
            );
            $payload['changes'] = array_map(function ($change) use ($current) {
                if (is_array($change) && isset($change['field'])) {
                    $change['from'] = $current[$change['field']] ?? null;
                }

                return $change;
            }, $payload['changes']);
        }

        return $payload;
    }

    private function buildPrompt(CallContext $call): string
    {
        $parts = ["Call id: {$call->callId}"];
        if (! empty($call->associations)) {
            $parts[] = 'Linked CRM objects: '.json_encode($call->associations);
        }
        $parts[] = "Transcript:\n".($call->transcript ?? '(no transcript available)');
        $parts[] = 'Draft the CRM update and reply with the JSON object only.';

        return implode("\n\n", $parts);
    }

    /**
     * @return array{target: array<string,mixed>|null, operation: ?string, changes: array<int,mixed>, rationale: string, confidence: float, evidence: array<int,mixed>}|null
     */
    private function parse(string $raw): ?array
    {
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw)));
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $operation = is_string($decoded['operation'] ?? null) ? $decoded['operation'] : 'none';

        return [
            'target' => is_array($decoded['target'] ?? null) ? $decoded['target'] : null,
            'operation' => $operation,
            'changes' => array_values(array_filter((array) ($decoded['changes'] ?? []), 'is_array')),
            'rationale' => Str::limit((string) ($decoded['rationale'] ?? ''), 200, ''),
            'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0))),
            'evidence' => array_values(array_filter((array) ($decoded['evidence'] ?? []), 'is_array')),
        ];
    }

    private function resolveProvider(string $model, User $user): Lab
    {
        $this->providers->applyRuntimeConfig($user);

        return $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
    }
}
