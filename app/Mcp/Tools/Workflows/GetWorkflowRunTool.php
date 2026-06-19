<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the full per-step trace of a workflow run by id (inputs, outputs, errors).')]
class GetWorkflowRunTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'run_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $run = WorkflowRun::query()
            ->where('app_id', $app->id)
            ->with('steps')
            ->find($validated['run_id']);

        if ($run === null) {
            return Response::error("No workflow run '{$validated['run_id']}' for that app.");
        }

        return Response::json([
            'id' => $run->id,
            'workflow_id' => $run->workflow_id,
            'status' => $run->status,
            'dry_run' => $run->dry_run,
            'error' => $run->error,
            'variables' => $run->variables,
            'steps' => $run->steps->map(fn ($s) => [
                'step_id' => $s->step_id,
                'step_type' => $s->step_type,
                'status' => $s->status,
                'input' => $s->input,
                'output' => $s->output,
                'error' => $s->error,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'run_id' => $schema->string()->description('The workflow run id.')->required(),
        ];
    }
}
