<?php

namespace App\Ai\Tools\Builder;

use App\Models\BuilderConversation;
use App\Services\Builder\BuildPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Declare which build-plan step(s) this turn will execute — they move to
 * in_progress. This is the ONLY thing the model says about progress; whether the
 * steps actually close is decided server-side at turn end from the applied
 * version (in_progress → done on apply, → failed on error, → pending if the turn
 * proposed nothing). So a turn that describes an edit but never proposes leaves
 * the step pending, not done.
 */
class TargetPlanStepsTool implements Tool
{
    public function __construct(private BuilderConversation $conversation) {}

    public function name(): string
    {
        return 'target_plan_steps';
    }

    public function description(): string
    {
        return <<<'DESC'
Mark the build-plan step(s) you are about to execute this turn as in_progress.
Pass `step_ids` (from set_build_plan / the current plan). Call it before your
propose_change for those steps. You never mark a step done — that happens
automatically when your propose_change applies this turn.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'step_ids' => $schema->array()
                ->description('Ids of the plan steps this turn will work on.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $plan = $this->conversation->build_plan;
        if (! is_array($plan) || ($plan['steps'] ?? []) === []) {
            return $this->fail('No build plan exists yet. Call set_build_plan first.');
        }

        $stepIds = $request->all()['step_ids'] ?? null;
        if (! is_array($stepIds) || $stepIds === []) {
            return $this->fail('`step_ids` must be a non-empty array of plan step ids.');
        }
        $stepIds = array_values(array_map('strval', $stepIds));

        $known = array_map(fn (array $s): string => $s['id'], $plan['steps']);
        $unknown = array_values(array_diff($stepIds, $known));
        if ($unknown !== []) {
            return $this->fail('Unknown step id(s): '.implode(', ', $unknown).'. Use ids from the current plan.');
        }

        $plan = BuildPlan::markInProgress($plan, $stepIds);
        $this->conversation->update(['build_plan' => $plan]);

        return json_encode([
            'ok' => true,
            'in_progress' => BuildPlan::inProgressIds($plan),
            'plan' => BuildPlan::compact($plan),
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
