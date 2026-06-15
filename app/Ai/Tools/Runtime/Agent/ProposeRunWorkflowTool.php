<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Services\Runtime\ProposedActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent write tool (builder power #3, gated). Records a PROPOSAL to run a
 * manifest workflow — it never executes; the user approves first (Rule 2). Scoped
 * to the manual-runnable workflows the manifest declares.
 */
class ProposeRunWorkflowTool implements Tool
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $allowedWorkflowIds
     */
    public function __construct(
        private array $manifest,
        private array $allowedWorkflowIds,
        private ProposedActions $proposals,
    ) {}

    public function name(): string
    {
        return 'propose_run_workflow';
    }

    public function description(): string
    {
        return <<<'DESC'
Propose running an automation (workflow). This does NOT run it — it prepares the
run for the user to approve (Rule 2). Pass the workflow_id and an optional `input`
map. Tell the user you've prepared it for approval; do not claim it ran.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->description('The workflow to run.')->required(),
            'input' => $schema->object()->description('Optional input map for the workflow.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $workflowId = $args['workflow_id'] ?? null;
        $input = $args['input'] ?? [];

        if (! is_string($workflowId) || ! in_array($workflowId, $this->allowedWorkflowIds, true)) {
            return json_encode(['ok' => false, 'error' => 'This workflow is not available to run.'], JSON_THROW_ON_ERROR);
        }

        $preview = 'Run workflow '.$this->workflowName($workflowId);

        $this->proposals->add([
            'type' => 'run_workflow',
            'workflow_id' => $workflowId,
            'input' => is_array($input) ? $input : [],
        ], $preview);

        return json_encode([
            'ok' => true,
            'preview' => $preview,
            'message' => 'Proposed — awaiting the user\'s approval. Not yet run.',
        ], JSON_THROW_ON_ERROR);
    }

    private function workflowName(string $workflowId): string
    {
        foreach ($this->manifest['workflows'] ?? [] as $wf) {
            if (($wf['id'] ?? null) === $workflowId) {
                return (string) ($wf['name'] ?? $wf['slug'] ?? $workflowId);
            }
        }

        return $workflowId;
    }
}
