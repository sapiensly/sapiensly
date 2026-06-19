<?php

namespace App\Ai\Tools\Builder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Capture-only tool. Before editing the manifest for a build request, the model
 * calls propose_plan with the trigger, the ordered steps, the external systems
 * each step touches (read/write), and the assumptions it made (each a default
 * the user can change in one touch). We store it and return a confirmation that
 * tells the model to STOP and let the user approve — the plan must be shown
 * before anything is built (discovery-as-proposal, FR-1).
 */
class ProposePlanTool implements Tool
{
    /** @var array<string, mixed>|null */
    private ?array $plan = null;

    public function name(): string
    {
        return 'propose_plan';
    }

    public function description(): string
    {
        return <<<'DESC'
Propose a PLAN before editing the manifest, for any new workflow or multi-step
build. State the outcome, the trigger, the ordered steps, every external system
each step touches (with read vs write), and your assumptions — each assumption a
sensible default the user can change, never a blank question.

After calling this, STOP: present the plan in plain language and let the user
approve, edit, or discard it. Do NOT call propose_change in the same turn — the
plan is shown BEFORE anything is built. When the user approves, the next turn
builds it with propose_change.

Skip this only for small, unambiguous tweaks to something that already exists.

Returns {ok: true}. The plan is shown to the user as a card.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('One line restating the outcome, e.g. "When a deal closes in HubSpot, post a summary to Slack and create a follow-up task."')
                ->required(),
            'trigger' => $schema->string()
                ->description('Plain description of what starts the flow, e.g. "HubSpot deal stage → closed".')
                ->required(),
            'steps' => $schema->array()
                ->description('Ordered steps. Each: {label, effect?: "read"|"write"|null, integration?: name}. effect is set only for steps that touch an external system.')
                ->required(),
            'touches' => $schema->array()
                ->description('Every external system touched, each {system, effect: "read"|"write"} — the blast radius shown before building. Empty if the flow touches no external system.'),
            'assumptions' => $schema->array()
                ->description('Defaults you chose, each {label, default}. e.g. {label: "Slack channel", default: "#sales"}. Never a blank question.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $this->plan = [
            'summary' => trim((string) ($args['summary'] ?? '')),
            'trigger' => trim((string) ($args['trigger'] ?? '')),
            'steps' => $this->normalizeList($args['steps'] ?? []),
            'touches' => $this->normalizeList($args['touches'] ?? []),
            'assumptions' => $this->normalizeList($args['assumptions'] ?? []),
        ];

        return json_encode([
            'ok' => true,
            'message' => 'Plan recorded and shown to the user. Do NOT edit the manifest yet — present the plan and stop. Build it only after the user approves.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(): ?array
    {
        return $this->plan;
    }

    /**
     * Keep only array entries (drop scalars the model might slip in) so the
     * stored plan is a clean list of objects the card can render.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
