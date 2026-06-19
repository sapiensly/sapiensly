<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Models\WorkflowProposal;
use App\Services\Workflows\WorkflowProposalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Approve a pending workflow proposal, executing its gated write. Confirm with the user first — this performs a real external/connector write.')]
class ApproveWorkflowProposalTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'proposal_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $proposal = WorkflowProposal::query()
            ->where('app_id', $app->id)
            ->where('status', 'pending')
            ->find($validated['proposal_id']);

        if ($proposal === null) {
            return Response::error("No pending proposal '{$validated['proposal_id']}' for that app.");
        }

        $outcome = app(WorkflowProposalService::class)->approve($proposal, $user);

        if (! ($outcome['ok'] ?? false)) {
            return Response::error('Approval failed: '.($outcome['error'] ?? 'unknown error'));
        }

        return Response::json(['approved' => true, 'result' => $outcome['result'] ?? null]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'proposal_id' => $schema->string()->description('The pending proposal id to approve.')->required(),
        ];
    }
}
