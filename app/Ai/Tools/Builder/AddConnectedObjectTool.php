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
Create a LIVE connected object from an MCP integration in ONE call — the fast
path for rule 1c-intent's DATA step. Pass `integration_id` (an is_mcp
connection) and `tool_name` (its list/search tool); optionally `arguments`
(clamped to the tool's input_schema bounds automatically; date args anchored
at today are stored as rolling {{today()}}/{{days_ago(N)}} expressions),
`collection_path` (auto-detected when omitted), `id_path` and `object_name`.
The SERVER calls the tool as the acting user, infers every field + field_map +
type from the real rows, and banks the object via propose_change. Use this
instead of hand-writing the object's propose_change (slow, error-prone); use
sample_mcp_tool first only if you still need to DISCOVER which tool to read.
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
                ->description('The MCP integration id (from list_available_integrations).')
                ->required(),
            'tool_name' => $schema->string()
                ->description('The MCP tool that lists the records (e.g. a search/list tool).')
                ->required(),
            'arguments' => $schema->object()
                ->description('Arguments for the tool call, per its input_schema. Numeric values outside the schema bounds are clamped.'),
            'collection_path' => $schema->string()
                ->description('Dot path to the row array inside the tool result. Omit to auto-detect.'),
            'id_path' => $schema->string()
                ->description("Dot path to each row's external id. Omit to auto-detect (an `id`-like key)."),
            'object_name' => $schema->string()
                ->description('Display name for the object. Omit to derive one from the tool name.'),
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
        if (! $integration->is_mcp) {
            return $this->fail('This integration is not an MCP server — connected objects over REST are authored via propose_change (see connected_objects).');
        }

        $base = $this->proposeTool->currentManifest();
        if (! is_array($base)) {
            return $this->fail('No active manifest exists for this app yet.');
        }

        $authored = $this->authoring->author($this->user, $integration, [
            'tool_name' => (string) ($args['tool_name'] ?? ''),
            'arguments' => is_array($args['arguments'] ?? null) ? $args['arguments'] : [],
            'collection_path' => is_string($args['collection_path'] ?? null) ? $args['collection_path'] : null,
            'id_path' => is_string($args['id_path'] ?? null) ? $args['id_path'] : null,
            'object_name' => is_string($args['object_name'] ?? null) ? $args['object_name'] : null,
        ], $base);

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
            'clamped_arguments' => $authored['clamped'] !== [] ? $authored['clamped'] : null,
            'message' => "Connected object «{$object['name']}» banked ({$object['slug']}, ".count($object['fields'])." fields, live via {$object['source']['operations']['list']['mcp_tool']}). Next: prepare_dashboard + add_dashboard_page.",
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
