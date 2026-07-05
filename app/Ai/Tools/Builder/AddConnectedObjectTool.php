<?php

namespace App\Ai\Tools\Builder;

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectModeler;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Tools\McpClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The one-call path from an MCP integration to a LIVE connected object. The
 * model names the integration + tool; the SERVER calls the tool as the acting
 * user, infers the fields/field_map/id_path from the real rows, clamps the
 * arguments to the tool's input_schema bounds (no more limit:500 against a
 * max:100 tool), and banks the object through propose_change — one checkpoint,
 * ~80 tokens of model output instead of minutes generating a 20-field patch
 * that a slow model's turn dies composing.
 */
class AddConnectedObjectTool implements Tool
{
    public function __construct(
        private ProposeChangeTool $proposeTool,
        private McpClient $mcp,
        private ConnectedObjectModeler $modeler,
        private IntegrationCatalog $catalog,
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
(clamped to the tool's input_schema bounds automatically), `collection_path`
(auto-detected when omitted), `id_path` and `object_name`. The SERVER calls the
tool as the acting user, infers every field + field_map + type from the real
rows, and banks the object via propose_change. Use this instead of hand-writing
the object's propose_change (slow, error-prone); use sample_mcp_tool first only
if you still need to DISCOVER which tool to read. Returns {ok, object,
fields, date_field_ids, sampled_rows} — go straight to prepare_dashboard next.
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

        $config = [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];

        $toolName = trim((string) ($args['tool_name'] ?? ''));

        try {
            $serverTools = $this->catalog->tools($integration, $this->user);
            $tool = collect($serverTools)->firstWhere('name', $toolName);
            if ($tool === null) {
                $names = implode(', ', array_column($serverTools, 'name'));

                return $this->fail("The MCP server has no tool named '{$toolName}'. Available: {$names}.");
            }

            [$arguments, $clamped] = $this->clampArguments(
                is_array($args['arguments'] ?? null) ? $args['arguments'] : [],
                $tool['input_schema'],
            );

            $decoded = $this->mcp->callToolData($config, $this->user, $toolName, $arguments);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        if ($decoded === null) {
            return $this->fail("The MCP tool '{$toolName}' did not return JSON rows.");
        }

        [$rows, $collectionPath] = $this->extractRows($decoded, $args['collection_path'] ?? null);
        if ($rows === []) {
            return $this->fail("The MCP tool '{$toolName}' returned no rows to model the object from. Result keys: ".implode(', ', array_keys($decoded)).'.');
        }

        $modeled = $this->modeler->model($rows, is_string($args['id_path'] ?? null) ? $args['id_path'] : null);
        if ($modeled['fields'] === []) {
            return $this->fail("Could not infer any fields from the rows of '{$toolName}'.");
        }

        $name = trim((string) ($args['object_name'] ?? '')) ?: Str::headline((string) preg_replace('/[-_]?tool$/i', '', $toolName));
        $slug = $this->uniqueObjectSlug($name, $base);

        $object = [
            'id' => 'obj_'.strtolower((string) Str::ulid()),
            'slug' => $slug,
            'name' => $name,
            'fields' => $modeled['fields'],
            'source' => array_filter([
                'type' => 'connected',
                'integration_id' => $integration->id,
                'id_path' => $modeled['id_path'],
                'operations' => ['list' => array_filter([
                    'mcp_tool' => $toolName,
                    // Stored arguments get today-anchored literal dates
                    // rewritten to rolling expressions ({{today()}},
                    // {{days_ago(N)}}) so the window doesn't freeze at the
                    // authoring date; the sampling call above used literals.
                    'arguments' => $arguments !== [] ? $this->relativizeDateArguments($arguments) : null,
                    'collection_path' => $collectionPath,
                ], fn ($v) => $v !== null)],
                'field_map' => $modeled['field_map'],
            ], fn ($v) => $v !== null),
        ];

        $result = $this->proposeTool->recordProposal(
            [['op' => 'add', 'path' => '/objects/-', 'value' => $object]],
            "Creé el objeto conectado «{$name}» (live desde {$toolName})",
        );

        if (($result['ok'] ?? false) !== true) {
            return json_encode($result, JSON_THROW_ON_ERROR);
        }

        // Feed the catalog so the NEXT build sees this tool's row shape in its
        // first list_available_integrations result — zero sampling rounds.
        $this->catalog->rememberShape(
            $integration,
            $toolName,
            $collectionPath,
            collect($modeled['fields'])->map(fn (array $f): array => [
                'path' => collect($modeled['field_map'])->firstWhere('field_id', $f['id'])['external_path'] ?? $f['slug'],
                'type' => $f['type'],
            ])->values()->all(),
        );

        $dateFieldIds = collect($modeled['fields'])
            ->filter(fn (array $f): bool => in_array($f['type'], ['date', 'datetime'], true))
            ->pluck('id')->values()->all();

        return json_encode($result + [
            'object' => ['id' => $object['id'], 'slug' => $slug, 'name' => $name],
            'fields' => collect($modeled['fields'])
                ->map(fn (array $f): array => ['id' => $f['id'], 'slug' => $f['slug'], 'type' => $f['type']])
                ->values()->all(),
            'date_field_ids' => $dateFieldIds,
            'sampled_rows' => count($rows),
            'clamped_arguments' => $clamped !== [] ? $clamped : null,
            'message' => "Connected object «{$name}» banked ({$slug}, ".count($modeled['fields'])." fields, live via {$toolName}). Next: prepare_dashboard + add_dashboard_page.",
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Pull the row list out of the decoded tool result: an explicit dot path,
     * a top-level list, or the first array value that is a list of assoc rows.
     *
     * @param  array<mixed>  $decoded
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function extractRows(array $decoded, mixed $explicitPath): array
    {
        if (is_string($explicitPath) && trim($explicitPath) !== '') {
            $path = trim($explicitPath);
            $rows = Arr::get($decoded, $path, []);

            return [is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [], $path];
        }

        if (array_is_list($decoded)) {
            return [array_values(array_filter($decoded, 'is_array')), null];
        }

        foreach ($decoded as $key => $value) {
            if (is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0])) {
                return [array_values(array_filter($value, 'is_array')), (string) $key];
            }
        }

        return [[], null];
    }

    /**
     * Rewrite a today-anchored literal date window to rolling expressions the
     * reader resolves per read: a value equal to today (UTC) becomes
     * {{today()}}, and past dates become {{days_ago(N)}} — but ONLY when the
     * window is anchored at today (some date equals today); a fully historical
     * window is deliberate and stays literal.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function relativizeDateArguments(array $arguments): array
    {
        $today = now()->utc()->startOfDay();
        $isDate = fn ($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;

        $anchoredAtToday = collect($arguments)
            ->contains(fn ($v): bool => $isDate($v) && $v === $today->toDateString());
        if (! $anchoredAtToday) {
            return $arguments;
        }

        foreach ($arguments as $key => $value) {
            if (! $isDate($value)) {
                continue;
            }
            if ($value === $today->toDateString()) {
                $arguments[$key] = '{{today()}}';

                continue;
            }
            $days = $today->diffInDays(Carbon::parse($value)->startOfDay(), false);
            if ($days < 0) {
                $arguments[$key] = '{{days_ago('.abs((int) $days).')}}';
            }
        }

        return $arguments;
    }

    /**
     * Clamp numeric arguments to the tool input_schema's minimum/maximum so a
     * mis-sized value degrades to the nearest allowed one instead of erroring
     * on every future live read.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $inputSchema
     * @return array{0: array<string, mixed>, 1: array<string, array{from: mixed, to: mixed}>}
     */
    private function clampArguments(array $arguments, array $inputSchema): array
    {
        $clamped = [];
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];

        foreach ($arguments as $key => $value) {
            $spec = $properties[$key] ?? null;
            if (! is_array($spec) || ! is_numeric($value)) {
                continue;
            }
            $bounded = $value;
            if (isset($spec['maximum']) && is_numeric($spec['maximum']) && $bounded > $spec['maximum']) {
                $bounded = $spec['maximum'];
            }
            if (isset($spec['minimum']) && is_numeric($spec['minimum']) && $bounded < $spec['minimum']) {
                $bounded = $spec['minimum'];
            }
            if ($bounded !== $value) {
                $clamped[$key] = ['from' => $value, 'to' => $bounded];
                $arguments[$key] = $bounded;
            }
        }

        return [$arguments, $clamped];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function uniqueObjectSlug(string $name, array $manifest): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(Str::ascii($name))), '_')) ?: 'connected';
        if (preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'o_'.$slug;
        }

        $taken = array_filter(array_map(fn ($o) => $o['slug'] ?? null, $manifest['objects'] ?? []));
        $candidate = $slug;
        $n = 2;
        while (in_array($candidate, $taken, true)) {
            $candidate = $slug.'_'.$n++;
        }

        return $candidate;
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
