<?php

namespace App\Ai\Tools\Builder;

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The one-call path from an MCP integration to a LIVE connected object. The
 * model names the integration + tool; the SERVER (ConnectedObjectAuthoring)
 * calls the tool as the acting user, infers the fields/field_map/id_path from
 * the real rows, clamps the arguments to the tool's input_schema bounds, and
 * this wrapper banks the object through propose_change — one checkpoint,
 * ~80 tokens of model output instead of minutes generating a 20-field patch
 * that a slow model's turn dies composing.
 */
class AddConnectedObjectTool implements Tool
{
    public function __construct(
        private ProposeChangeTool $proposeTool,
        private ConnectedObjectAuthoring $authoring,
        private User $user,
    ) {}

    public function name(): string
    {
        return 'add_connected_object';
    }

    public function description(): string
    {
        return <<<'DESC'
Create a LIVE connected object from ANY integration in ONE call — the fast
path for rule 1c-intent's DATA step. Pass `integration_id`, then name the read
the way that connection works: `tool_name` for an MCP server (its list/search
tool), or `path` for a REST API (its list endpoint, e.g. "/crm/v3/objects/deals?limit=50",
with `method` defaulting to GET). Never hand-write a connected object's
propose_change for either transport — that is twenty fields, a field_map and an
id_path composed by you, and it is the largest rejection class in the ledger.
For MCP, optionally `arguments`
(clamped to the tool's input_schema bounds automatically; date args anchored
at today are stored as rolling {{today()}}/{{days_ago(N)}} expressions),
`collection_path` (auto-detected when omitted), `id_path` and `object_name`.
The SERVER performs the read as the acting user, infers every field + field_map +
type from the real rows, and banks the object via propose_change. The read IS the
verification: an endpoint or tool that fails, or returns no rows, is reported here
rather than becoming an object that errors on every page load. Use sample_mcp_tool
(MCP) or sample_endpoint (REST) first only if you still need to DISCOVER what to read.
Returns {ok, object, fields, date_field_ids, derived_rates, immature_periods,
sampled_rows} — go straight to prepare_dashboard next. READ `immature_periods`
BEFORE YOU CHART ANYTHING: a live source reports today's orders instantly but
cannot mark them delivered-on-time until their promised date arrives, so the last
days of the series read as a collapse to zero when in fact nothing has happened
there yet. Filter those periods out of every KPI and chart, and never title a
block or write an insight about a "drop" at the end of a series — that drop is
the calendar. READ `derived_rates` before you build a KPI:
it names every rate column the sampled rows PROVE is derived from other columns
(e.g. otd_pct = (delivered - late) / total, verified on 61/61 rows) and tells you
exactly how to compute it. Averaging such a rate is NOT an approximation — it is a
different number, because the mean weights a day with 3 orders like a day with 500.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('The integration id (from list_available_integrations). MCP or REST.')
                ->required(),
            // Neither is `required`: which one applies is decided by the
            // connection's transport, and the handler says which it wanted.
            'tool_name' => $schema->string()
                ->description('MCP connections: the tool that lists the records (e.g. a search/list tool).'),
            'path' => $schema->string()
                ->description('REST connections: the list endpoint, appended to the base URL, query string included (e.g. "/crm/v3/objects/deals?limit=50").'),
            'method' => $schema->string()
                ->description('REST connections: GET (default) or POST for a search endpoint. A list operation reads, so nothing else is accepted.'),
            'arguments' => $schema->object()
                ->description('MCP connections: arguments for the tool call, per its input_schema. Numeric values outside the schema bounds are clamped.'),
            'collection_path' => $schema->string()
                ->description('Dot path to the row array inside the response. Omit to auto-detect.'),
            'id_path' => $schema->string()
                ->description("Dot path to each row's external id. Omit to auto-detect (an `id`-like key)."),
            'object_name' => $schema->string()
                ->description('Display name for the object. Omit to derive one from the tool name or path.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $integration = Integration::query()
            ->forAccountContext($this->user)
            ->find((string) ($args['integration_id'] ?? ''));
        if (! $integration instanceof Integration) {
            return $this->fail('Integration not found for this tenant.');
        }
        $base = $this->proposeTool->currentManifest();
        if (! is_array($base)) {
            return $this->fail('No active manifest exists for this app yet.');
        }

        $toolName = trim((string) ($args['tool_name'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));

        // The transport decides which half runs, and the connection knows its own
        // transport — so a caller that named the wrong one is told which one this
        // connection takes rather than being handed a generic failure.
        if ($integration->is_mcp) {
            if ($toolName === '') {
                return $this->fail("«{$integration->name}» is an MCP connection: pass `tool_name` (its list/search tool), not `path`.");
            }

            $authored = $this->authoring->author($this->user, $integration, [
                'tool_name' => $toolName,
                'arguments' => is_array($args['arguments'] ?? null) ? $args['arguments'] : [],
                'collection_path' => is_string($args['collection_path'] ?? null) ? $args['collection_path'] : null,
                'id_path' => is_string($args['id_path'] ?? null) ? $args['id_path'] : null,
                'object_name' => is_string($args['object_name'] ?? null) ? $args['object_name'] : null,
            ], $base);
        } else {
            if ($path === '') {
                return $this->fail("«{$integration->name}» is a REST connection: pass `path` (its list endpoint), not `tool_name`.");
            }

            $authored = $this->authoring->authorRest($this->user, $integration, [
                'path' => $path,
                'method' => is_string($args['method'] ?? null) ? $args['method'] : null,
                'collection_path' => is_string($args['collection_path'] ?? null) ? $args['collection_path'] : null,
                'id_path' => is_string($args['id_path'] ?? null) ? $args['id_path'] : null,
                'object_name' => is_string($args['object_name'] ?? null) ? $args['object_name'] : null,
            ], $base);
        }

        if (($authored['ok'] ?? false) !== true) {
            return $this->fail($authored['error'] ?? 'Could not author the connected object.');
        }

        $object = $authored['object'];

        $result = $this->proposeTool->recordProposal(
            [['op' => 'add', 'path' => '/objects/-', 'value' => $object]],
            $authored['summary'],
        );

        if (($result['ok'] ?? false) !== true) {
            return json_encode($result, JSON_THROW_ON_ERROR);
        }

        return json_encode($result + [
            'object' => ['id' => $object['id'], 'slug' => $object['slug'], 'name' => $object['name']],
            'fields' => collect($object['fields'])
                ->map(fn (array $f): array => ['id' => $f['id'], 'slug' => $f['slug'], 'type' => $f['type']])
                ->values()->all(),
            'date_field_ids' => $authored['date_field_ids'],
            // Rate columns the sampled rows PROVE are derived from others. Read the
            // `guidance` on each before building any KPI from them: averaging a
            // derived rate is a different number, not an approximation.
            'derived_rates' => $authored['derived_rates'],
            // Trailing periods that have NOT RESOLVED yet. Read literally they look
            // like a collapse to zero; they are the calendar, not the business.
            'immature_periods' => $authored['immature_periods'],
            'sampled_rows' => count($authored['rows']),
            'clamped_arguments' => ($authored['clamped'] ?? []) !== [] ? $authored['clamped'] : null,
            'message' => "Connected object «{$object['name']}» banked ({$object['slug']}, ".count($object['fields'])." fields, live via {$this->describeListOperation($object)}). Next: prepare_dashboard + add_dashboard_page.",
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * How this object reads, for the confirmation line — the MCP tool it calls
     * or the endpoint it hits.
     *
     * @param  array<string, mixed>  $object
     */
    private function describeListOperation(array $object): string
    {
        $list = $object['source']['operations']['list'] ?? [];

        return isset($list['mcp_tool'])
            ? (string) $list['mcp_tool']
            : trim(((string) ($list['method'] ?? 'GET')).' '.((string) ($list['path'] ?? '')));
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
