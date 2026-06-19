<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Run a workflow for real (writes apply). External/connector writes pause for approval — see list_workflow_proposals. Use verify_workflow first for a safe dry-run.')]
class RunWorkflowTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'workflow_id' => ['required', 'string'],
            'trigger_payload' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);
        $workflow = collect($manifest['workflows'] ?? [])->firstWhere('id', $validated['workflow_id']);

        if ($workflow === null) {
            return Response::error("No workflow '{$validated['workflow_id']}' in app '{$app->slug}'.");
        }

        $run = app(WorkflowEngine::class)->run(
            $app,
            $manifest,
            $workflow,
            'manual',
            $validated['trigger_payload'] ?? [],
            $user,
            dryRun: false,
        );

        $run->load('steps');

        return Response::json([
            'run_id' => $run->id,
            'status' => $run->status,
            'error' => $run->error,
            'steps' => $run->steps->map(fn ($s) => [
                'step_id' => $s->step_id,
                'status' => $s->status,
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
            'app_slug' => $schema->string()->description('The slug of the app that owns the workflow.')->required(),
            'workflow_id' => $schema->string()->description('The id of the workflow to run.')->required(),
            'trigger_payload' => $schema->object()->description('Optional payload passed to the trigger.'),
        ];
    }
}
