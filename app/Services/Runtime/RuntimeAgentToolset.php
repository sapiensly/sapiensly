<?php

namespace App\Services\Runtime;

use App\Ai\Tools\Platform\McpBridgeTool;
use App\Ai\Tools\Runtime\Agent\AggregateObjectTool;
use App\Ai\Tools\Runtime\Agent\DescribeCapabilitiesTool;
use App\Ai\Tools\Runtime\Agent\ProposeCreateRecordTool;
use App\Ai\Tools\Runtime\Agent\ProposeDeleteRecordTool;
use App\Ai\Tools\Runtime\Agent\ProposeRunWorkflowTool;
use App\Ai\Tools\Runtime\Agent\ProposeUpdateRecordTool;
use App\Ai\Tools\Runtime\Agent\QueryObjectTool;
use App\Ai\Tools\RuntimeToolFactory;
use App\Mcp\Tools\Account\CurrentDatetimeTool;
use App\Models\App;
use App\Services\Apps\AppAccessContext;
use App\Services\Records\AppDataOverview;
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
        private AppDataOverview $dataOverview,
    ) {}

    /**
     * The full toolset for a turn: read tools plus the gated propose_* write
     * tools. The write tools never execute — they record into $proposals, which
     * the service turns into an action_proposal awaiting approval (Rule 2).
     *
     * $access narrows the manifest grant to the requesting user's app role: an
     * object the role can't read/write drops out of the toolset entirely (the
     * agent can never exceed the user it acts for). Defaults to bypass so callers
     * that don't enforce policy (and the existing tests) see the full grant.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context  base render context threaded into reads (current_user, __access)
     * @return list<Tool>
     */
    public function tools(App $app, array $manifest, ProposedActions $proposals, ?AppAccessContext $access = null, array $context = []): array
    {
        $access ??= AppAccessContext::bypass();

        return [
            // The clock, so the app's agent grounds time-relative answers ("in
            // the last 7 days") instead of guessing. This toolset is sandboxed
            // off the PlatformToolsFactory path, so bridge it in explicitly.
            RuntimeToolFactory::named('current_datetime', new McpBridgeTool(CurrentDatetimeTool::class, $app->user)),
            ...$this->readTools($app, $manifest, $access, $context),
            ...$this->writeTools($manifest, $proposals, $access),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return list<Tool>
     */
    public function readTools(App $app, array $manifest, ?AppAccessContext $access = null, array $context = []): array
    {
        $access ??= AppAccessContext::bypass();

        $agent = $manifest['agent'] ?? null;
        if (! is_array($agent) || ($agent['enabled'] ?? false) !== true) {
            return [];
        }

        $readableObjectIds = array_values(array_filter(
            $this->resolveObjectIds($manifest, $agent['capabilities']['read'] ?? []),
            fn (string $id): bool => $access->can($id, 'read'),
        ));

        $tools = [
            RuntimeToolFactory::named('describe_capabilities', new DescribeCapabilitiesTool($app, $manifest, $readableObjectIds, $this->dataOverview)),
        ];

        // The read tools carry the access context so row_filter and hidden-field
        // restrictions apply to the agent's reads exactly as they do to the UI.
        $readContext = $context + ['__access' => $access];

        // No readable objects ⇒ only describe_capabilities (which reports an
        // empty list) — there is nothing to query or aggregate.
        if ($readableObjectIds !== []) {
            $tools[] = RuntimeToolFactory::named('query_object', new QueryObjectTool($app, $manifest, $readableObjectIds, $this->blockData, $readContext));
            $tools[] = RuntimeToolFactory::named('aggregate_object', new AggregateObjectTool($app, $manifest, $readableObjectIds, $this->records, $this->blockData, $readContext));
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
    public function writeTools(array $manifest, ProposedActions $proposals, ?AppAccessContext $access = null): array
    {
        $access ??= AppAccessContext::bypass();

        $agent = $manifest['agent'] ?? null;
        if (! is_array($agent) || ($agent['enabled'] ?? false) !== true) {
            return [];
        }

        $writableObjectIds = array_values(array_filter(
            $this->resolveObjectIds($manifest, $agent['capabilities']['write'] ?? []),
            fn (string $id): bool => $access->can($id, 'create') || $access->can($id, 'update') || $access->can($id, 'delete'),
        ));
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
