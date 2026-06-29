<?php

namespace App\Ai\Tools\Builder;

use App\Models\BuilderConversation;
use App\Services\Builder\BuildPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Create or edit the conversation's persistent build plan — an ordered list of
 * steps the builder works through across turns. Reconciles by step id (existing
 * ids keep their progress; omitted ids are minted; dropped steps are preserved),
 * so calling it again to add/reorder/refine steps never wipes what's already
 * done. Step COMPLETION is not set here — it's derived when a turn's
 * propose_change actually applies (see {@see BuildPlan}).
 */
class SetBuildPlanTool implements Tool
{
    public function __construct(private BuilderConversation $conversation) {}

    public function name(): string
    {
        return 'set_build_plan';
    }

    public function description(): string
    {
        return <<<'DESC'
Create or update the build plan for a multi-part goal — the ordered steps you'll
work through over several turns. Pass `steps` (each: {title, detail?}; include the
existing `id` when editing a step so its progress is kept). Call this FIRST when a
request has 3+ distinct pieces, then execute one or two steps per turn. You do NOT
mark steps done — completion is tracked automatically when your propose_change
applies. Returns the reconciled plan with canonical step ids.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()
                ->description('One-line statement of the overall objective (optional).'),
            'steps' => $schema->array()
                ->description('Ordered steps: [{id?: string (pass the existing id when editing a step), title: string (required), detail?: string}]. Omit id for new steps — the server mints it.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $input = $request->all();
        $steps = $input['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return $this->fail('`steps` must be a non-empty array of {title, detail?}.');
        }

        $plan = BuildPlan::reconcile(
            $this->conversation->build_plan,
            isset($input['goal']) ? (string) $input['goal'] : null,
            array_values($steps),
        );

        $this->conversation->update(['build_plan' => $plan]);

        return json_encode([
            'ok' => true,
            'plan' => BuildPlan::compact($plan),
            'message' => 'Build plan saved. Target the step(s) you will execute this turn with target_plan_steps, then propose_change — progress is closed automatically when it applies.',
        ], JSON_THROW_ON_ERROR);
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
