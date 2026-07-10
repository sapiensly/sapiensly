<?php

namespace App\Services\Connected;

use App\Models\Integration;
use App\Models\User;
use App\Services\Tools\McpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The server-side heart of add_connected_object, extracted so the Express
 * pipeline can author connected objects WITHOUT the tool wrapper (no turn
 * accumulator): call the MCP tool as the acting user, clamp arguments to the
 * input_schema, infer the fields from the real rows, and return the finished
 * manifest node + the sampled rows (the pipeline reuses them for computed
 * facts). Callers decide how to bank: the builder tool records a proposal,
 * the pipeline batches ops into one applied version.
 */
class ConnectedObjectAuthoring
{
    private const DATE_FROM_PARAM = '/(^|_)(from|start|since|desde)(_|$)|_from$|^fecha_desde$/i';

    private const DATE_TO_PARAM = '/(^|_)(to|until|end|hasta)(_|$)|_to$|^fecha_hasta$/i';

    public function __construct(
        private readonly McpClient $mcp,
        private readonly ConnectedObjectModeler $modeler,
        private readonly IntegrationCatalog $catalog,
    ) {}

    /**
     * @param  array{tool_name: string, arguments?: array<string, mixed>, collection_path?: ?string, id_path?: ?string, object_name?: ?string}  $spec
     * @param  array<string, mixed>  $manifest  current draft (slug uniqueness)
     * @return array{ok: bool, object?: array<string, mixed>, rows?: list<array<string, mixed>>, clamped?: array<string, mixed>, date_field_ids?: list<string>, summary?: string, error?: string}
     */
    public function author(User $user, Integration $integration, array $spec, array $manifest): array
    {
        $toolName = trim((string) ($spec['tool_name'] ?? ''));
        if ($toolName === '') {
            return ['ok' => false, 'error' => '`tool_name` is required.'];
        }

        $config = [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];

        try {
            $serverTools = $this->catalog->tools($integration, $user);
            $tool = collect($serverTools)->firstWhere('name', $toolName);
            if ($tool === null) {
                $names = implode(', ', array_column($serverTools, 'name'));

                return ['ok' => false, 'error' => "The MCP server has no tool named '{$toolName}'. Available: {$names}."];
            }

            [$arguments, $clamped] = $this->clampArguments(
                is_array($spec['arguments'] ?? null) ? $spec['arguments'] : [],
                $tool['input_schema'],
            );
            // Fill REQUIRED params the caller didn't provide (the Express
            // pipeline names only the tool): date-ish params get a rolling
            // 30-day window, enums a sensible member, bounded numbers their
            // maximum. Without this, a from/to-requiring tool errors on the
            // very first read.
            $arguments = $this->fillRequiredArguments($arguments, $tool['input_schema']);

            try {
                $decoded = $this->mcp->callToolData($config, $user, $toolName, $arguments);
            } catch (\Throwable $e) {
                // "At least one of sku/fecha_desde/fecha_hasta…" constraints
                // live only in the tool's ERROR message — the date params are
                // optional in the schema, so fillRequiredArguments never sees
                // them. One retry with a synthesized rolling window over the
                // optional date-ish params before giving up.
                $withDates = $this->fillDateArguments($arguments, $tool['input_schema']);
                if ($withDates === $arguments) {
                    throw $e;
                }
                $decoded = $this->mcp->callToolData($config, $user, $toolName, $withDates);
                $arguments = $withDates;
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($decoded === null) {
            return ['ok' => false, 'error' => "The MCP tool '{$toolName}' did not return JSON rows."];
        }

        [$rows, $collectionPath] = $this->extractRows($decoded, $spec['collection_path'] ?? null);
        if ($rows === []) {
            // Remember the miss: an empty shape marks a summary-only tool so
            // the NEXT run's fit-check can avoid it instead of re-paying the
            // wasted acquisition.
            $this->catalog->rememberShape($integration, $toolName, null, []);

            return ['ok' => false, 'error' => "The MCP tool '{$toolName}' returned no rows to model the object from. Result keys: ".implode(', ', array_keys($decoded)).'.'];
        }

        $modeled = $this->modeler->model($rows, is_string($spec['id_path'] ?? null) ? $spec['id_path'] : null);
        if ($modeled['fields'] === []) {
            return ['ok' => false, 'error' => "Could not infer any fields from the rows of '{$toolName}'."];
        }

        $name = trim((string) ($spec['object_name'] ?? ''))
            ?: Str::headline((string) preg_replace(['/^get[-_]/i', '/[-_]?tool$/i'], '', $toolName));
        $slug = $this->uniqueObjectSlug($name, $manifest);

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
                    'arguments' => $arguments !== [] ? $this->relativizeDateArguments($arguments) : null,
                    'collection_path' => $collectionPath,
                ], fn ($v) => $v !== null)],
                'field_map' => $modeled['field_map'],
            ], fn ($v) => $v !== null),
        ];

        // Feed the catalog so the NEXT build sees this tool's row shape in its
        // first discovery — zero sampling rounds.
        $this->catalog->rememberShape(
            $integration,
            $toolName,
            $collectionPath,
            collect($modeled['fields'])->map(fn (array $f): array => [
                'path' => collect($modeled['field_map'])->firstWhere('field_id', $f['id'])['external_path'] ?? $f['slug'],
                'type' => $f['type'],
            ])->values()->all(),
        );

        return [
            'ok' => true,
            'object' => $object,
            'rows' => $rows,
            'clamped' => $clamped,
            'date_field_ids' => collect($modeled['fields'])
                ->filter(fn (array $f): bool => in_array($f['type'], ['date', 'datetime'], true))
                ->pluck('id')->values()->all(),
            'summary' => "Creé el objeto conectado «{$name}» (live desde {$toolName})",
        ];
    }

    /**
     * Pull the row list out of the decoded tool result: an explicit dot path,
     * a top-level list, or the first array value that is a list of assoc rows.
     *
     * @param  array<mixed>  $decoded
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    /**
     * Raw rows for the PREVIOUS window of an already-authored connected
     * object: the same tool call with its from/to shifted back one span, so
     * the facts layer can compute real period-over-period deltas ("Duplicado:
     * 94, +18% vs periodo anterior") instead of static numbers. Best-effort
     * by design — [] when the object carries no window arguments (a
     * granularity-only series compares against its own history instead) or
     * when the read fails: the double window is an enrichment, never a build
     * risk. Rows come back RAW (external shape), matching what the sampler
     * feeds ComputedFactsBuilder.
     *
     * @param  array<string, mixed>  $object
     * @return list<array<string, mixed>>
     */
    public function previousWindowRows(User $user, Integration $integration, array $object): array
    {
        $op = $object['source']['operations']['list'] ?? null;
        $toolName = trim((string) ($op['mcp_tool'] ?? ''));
        $arguments = is_array($op['arguments'] ?? null) ? $op['arguments'] : [];
        if ($toolName === '' || $arguments === []) {
            return [];
        }

        $fromKey = collect(ConnectedObjectReader::DATE_FROM_ARG_KEYS)->first(fn (string $k) => array_key_exists($k, $arguments));
        $toKey = collect(ConnectedObjectReader::DATE_TO_ARG_KEYS)->first(fn (string $k) => array_key_exists($k, $arguments));
        $from = $this->windowDate($arguments[$fromKey ?? ''] ?? null);
        if ($fromKey === null || $from === null) {
            return [];
        }
        $to = $this->windowDate($arguments[$toKey ?? ''] ?? null) ?? now()->utc()->startOfDay();

        $spanDays = max(1, (int) $from->diffInDays($to));
        $arguments[$fromKey] = $from->copy()->subDays($spanDays)->toDateString();
        if ($toKey !== null) {
            $arguments[$toKey] = $from->toDateString();
        }

        try {
            $decoded = $this->mcp->callToolData([
                'endpoint' => $integration->base_url,
                'integration_id' => $integration->id,
                'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
                'auth_config' => $integration->auth_config ?? [],
            ], $user, $toolName, $arguments);
        } catch (\Throwable) {
            return [];
        }
        if ($decoded === null) {
            return [];
        }

        [$rows] = $this->extractRows($decoded, $op['collection_path'] ?? null);

        return $rows;
    }

    /**
     * Concretize one authored window argument: the two relative template
     * forms relativizeDateArguments writes, or a literal Y-m-d.
     */
    private function windowDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $today = now()->utc()->startOfDay()->toImmutable();
        if ($value === '{{today()}}') {
            return $today;
        }
        if (preg_match('/^\{\{days_ago\((\d+)\)\}\}$/', $value) === 1) {
            preg_match('/\d+/', $value, $m);

            return $today->subDays((int) $m[0]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC');

            return $parsed === false ? null : $parsed->startOfDay();
        }

        return null;
    }

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

        // One level deeper: aggregate tools often nest the list inside a
        // wrapper object ({by_dimension: {status: [...]}}, {data: {rows: [...]}}).
        foreach ($decoded as $key => $value) {
            if (! is_array($value) || array_is_list($value)) {
                continue;
            }
            foreach ($value as $childKey => $child) {
                if (is_array($child) && array_is_list($child) && $child !== [] && is_array($child[0])) {
                    return [array_values(array_filter($child, 'is_array')), $key.'.'.$childKey];
                }
            }
        }

        return [[], null];
    }

    /**
     * Rewrite a today-anchored literal date window to rolling expressions the
     * reader resolves per read; a fully historical window is deliberate and
     * stays literal.
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
     * Synthesize values for required input_schema params that are missing:
     * start-ish dates → 30 days ago, end-ish dates → today (the stored window
     * then rolls via relativizeDateArguments), enums → prefer a weekly-ish
     * member else the first, bounded integers → their maximum, booleans →
     * false. Anything unrecognizable is left absent — the tool's own error
     * then names it.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $inputSchema
     * @return array<string, mixed>
     */
    private function fillRequiredArguments(array $arguments, array $inputSchema): array
    {
        $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];

        foreach ($required as $name) {
            if (! is_string($name) || array_key_exists($name, $arguments)) {
                continue;
            }
            $spec = is_array($properties[$name] ?? null) ? $properties[$name] : [];
            $type = $spec['type'] ?? 'string';
            $enum = is_array($spec['enum'] ?? null) ? $spec['enum'] : [];

            if ($enum !== []) {
                $weekly = collect($enum)->first(fn ($v) => is_string($v) && preg_match('/week|semana/i', $v) === 1);
                $arguments[$name] = $weekly ?? $enum[0];

                continue;
            }
            // Contain-style: fecha_desde / date_from / start_date all count
            // (an exact-match regex left fecha_desde unfilled and the tool
            // rejected every read in a benchmark run).
            if (preg_match(self::DATE_FROM_PARAM, $name) === 1) {
                $arguments[$name] = now()->utc()->subDays(30)->toDateString();

                continue;
            }
            if (preg_match(self::DATE_TO_PARAM, $name) === 1) {
                $arguments[$name] = now()->utc()->toDateString();

                continue;
            }
            if (in_array($type, ['integer', 'number'], true)) {
                $arguments[$name] = $spec['maximum'] ?? $spec['minimum'] ?? 100;

                continue;
            }
            if ($type === 'boolean') {
                $arguments[$name] = false;
            }
            // Unrecognizable required string → leave absent; the tool's error
            // message will name it and the run reports it honestly.
        }

        return $arguments;
    }

    /**
     * Fill EVERY missing date-ish param (required or not) with the rolling
     * 30-day window — the retry path for tools whose "provide at least one
     * filter" constraint is enforced only at call time.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $inputSchema
     * @return array<string, mixed>
     */
    private function fillDateArguments(array $arguments, array $inputSchema): array
    {
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];

        foreach (array_keys($properties) as $name) {
            if (! is_string($name) || array_key_exists($name, $arguments)) {
                continue;
            }
            if (preg_match(self::DATE_FROM_PARAM, $name) === 1) {
                $arguments[$name] = now()->utc()->subDays(30)->toDateString();
            } elseif (preg_match(self::DATE_TO_PARAM, $name) === 1) {
                $arguments[$name] = now()->utc()->toDateString();
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
}
