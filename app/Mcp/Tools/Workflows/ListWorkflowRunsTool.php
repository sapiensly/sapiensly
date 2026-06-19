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

#[Description("List an app's recent workflow runs (newest first) with their status — for debugging.")]
class ListWorkflowRunsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $runs = WorkflowRun::query()
            ->where('app_id', $app->id)
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 20)
            ->get();

        return Response::json([
            'runs' => $runs->map(fn (WorkflowRun $r) => [
                'id' => $r->id,
                'workflow_id' => $r->workflow_id,
                'trigger_type' => $r->trigger_type,
                'status' => $r->status,
                'dry_run' => $r->dry_run,
                'error' => $r->error,
                'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
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
            'limit' => $schema->integer()->description('Max runs to return (default 20).'),
        ];
    }
}
