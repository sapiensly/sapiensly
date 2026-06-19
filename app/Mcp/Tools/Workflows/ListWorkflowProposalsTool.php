<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Models\WorkflowProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List pending workflow write-approvals for an app (external/connector writes a run paused on). Approve or dismiss each with the matching tool.')]
class ListWorkflowProposalsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $proposals = WorkflowProposal::query()
            ->where('app_id', $app->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return Response::json([
            'proposals' => $proposals->map(fn (WorkflowProposal $p) => [
                'id' => $p->id,
                'workflow_id' => $p->workflow_id,
                'run_id' => $p->run_id,
                'step_id' => $p->step_id,
                'effect' => $p->effect,
                'preview' => $p->preview,
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
        ];
    }
}
