<?php

namespace App\Services\Runtime;

use App\Ai\Tools\Runtime\Agent\AggregateObjectTool;
use App\Ai\Tools\Runtime\Agent\DescribeCapabilitiesTool;
use App\Ai\Tools\Runtime\Agent\ProposeCreateRecordTool;
use App\Ai\Tools\Runtime\Agent\ProposeDeleteRecordTool;
use App\Ai\Tools\Runtime\Agent\ProposeRunWorkflowTool;
use App\Ai\Tools\Runtime\Agent\ProposeUpdateRecordTool;
use App\Ai\Tools\Runtime\Agent\QueryObjectTool;
use App\Ai\Tools\RuntimeToolFactory;
use App\Models\App;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordQueryService;
use Laravel\Ai\Contracts\Tool;

/**
 * Derives the runtime agent's toolset from a built app's manifest (builder power
 * #3). Every granted capability becomes a tool automatically — "free at the
 * toolset level" (vision §7). The agent consumes capabilities, never defines
 * them, and can never exceed what `manifest.agent.capabilities` grants: an
 * ungranted object is simply not in any tool's reach.
 *
 * Read slice (this slice): the read tools (query/aggregate/describe), source-
 * agnostic over internal records and connected objects. The propose-* write
 * tools (gated) land in the write slice.
 */
class RuntimeAgentToolset
{
    public function __construct(
        private BlockDataResolver $blockData,
        private RecordQueryService $records,
    ) {}

    /**
     * The full toolset for a turn: read tools plus the gated propose_* write
     * tools. The write tools never execute — they record into $proposals, which
     * the service turns into an action_proposal awaiting approval (Rule 2).
     *
     * @param  array<string, mixed>  $manifest
     * @return list<Tool>
     */
    public function tools(App $app, array $manifest, ProposedActions $proposals): array
    {
        return [...$this->readTools($app, $manifest), ...$this->writeTools($manifest, $proposals)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<Tool>
     */
    public function readTools(App $app, array $manifest): array
    {
        $agent = $manifest['agent'] ?? null;
        if (! is_array($agent) || ($agent['enabled'] ?? false) !== true) {
            return [];
        }

        $readableObjectIds = $this->resolveObjectIds($manifest, $agent['capabilities']['read'] ?? []);

        $tools = [
            RuntimeToolFactory::named('describe_capabilities', new DescribeCapabilitiesTool($manifest, $readableObjectIds)),
        ];

        // No readable objects ⇒ only describe_capabilities (which reports an
        // empty list) — there is nothing to query or aggregate.
        if ($readableObjectIds !== []) {
            $tools[] = RuntimeToolFactory::named('query_object', new QueryObjectTool($app, $manifest, $readableObjectIds, $this->blockData));
            $tools[] = RuntimeToolFactory::named('aggregate_object', new AggregateObjectTool($app, $manifest, $readableObjectIds, $this->records, $this->blockData));
        }

        return $tools;
    }

    /**
     * The gated write tools, scoped to the objects the agent may write. Each one
     * records a proposal into $proposals — none of them mutate. propose_run_workflow
     * is offered whenever the agent has any write grant and the app has workflows.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<Tool>
     */
    public function writeTools(array $manifest, ProposedActions $proposals): array
    {
        $agent = $manifest['agent'] ?? null;
        if (! is_array($agent) || ($agent['enabled'] ?? false) !== true) {
            return [];
        }

        $writableObjectIds = $this->resolveObjectIds($manifest, $agent['capabilities']['write'] ?? []);
        if ($writableObjectIds === []) {
            return [];
        }

        $tools = [
            RuntimeToolFactory::named('propose_create_record', new ProposeCreateRecordTool($manifest, $writableObjectIds, $proposals)),
            RuntimeToolFactory::named('propose_update_record', new ProposeUpdateRecordTool($manifest, $writableObjectIds, $proposals)),
            RuntimeToolFactory::named('propose_delete_record', new ProposeDeleteRecordTool($manifest, $writableObjectIds, $proposals)),
        ];

        $workflowIds = array_values(array_filter(array_map(
            fn ($w) => $w['id'] ?? null,
            $manifest['workflows'] ?? [],
        )));
        if ($workflowIds !== []) {
            $tools[] = RuntimeToolFactory::named('propose_run_workflow', new ProposeRunWorkflowTool($manifest, $workflowIds, $proposals));
        }

        return $tools;
    }

    /**
     * Resolve a capability grant ("all" or an explicit id list) to the concrete
     * object ids that actually exist in the manifest — fail-closed: an unknown
     * grant shape resolves to none.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function resolveObjectIds(array $manifest, mixed $grant): array
    {
        $all = array_values(array_filter(array_map(
            fn ($o) => $o['id'] ?? null,
            $manifest['objects'] ?? [],
        )));

        if ($grant === 'all') {
            return $all;
        }

        if (! is_array($grant)) {
            return [];
        }

        return array_values(array_intersect($all, $grant));
    }
}
