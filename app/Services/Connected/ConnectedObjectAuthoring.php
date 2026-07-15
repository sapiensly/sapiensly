<?php

namespace App\Services\Connected;

use App\Models\Integration;
use App\Models\User;
use App\Services\Analyst\MaturationCheck;
use App\Services\Analyst\RatioIdentity;
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
        $resolved = $this->resolveCall($user, $integration, $spec);
        if (($resolved['ok'] ?? false) !== true) {
            return $resolved;
        }

        try {
            try {
                $decoded = $this->mcp->callToolData($resolved['config'], $user, $resolved['tool_name'], $resolved['arguments']);
            } catch (\Throwable $e) {
                // "At least one of sku/fecha_desde/fecha_hasta…" constraints
                // live only in the tool's ERROR message — the date params are
                // optional in the schema, so fillRequiredArguments never sees
                // them. One retry with a synthesized rolling window over the
                // optional date-ish params before giving up.
                $withDates = $this->fillDateArguments($resolved['arguments'], $resolved['input_schema']);
                if ($withDates === $resolved['arguments']) {
                    throw $e;
                }
                $decoded = $this->mcp->callToolData($resolved['config'], $user, $resolved['tool_name'], $withDates);
                $resolved['arguments'] = $withDates;
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return $this->authorFromDecoded($integration, $spec, $resolved, $decoded, $manifest);
    }

    /**
     * Author MANY connected objects with their tool reads POOLED into ONE
     * round-trip: the serial author() run N times paid N network latencies;
     * this resolves every spec, fires all fetches at once (poolToolCalls
     * collapses the sum to the max), refires the date-constrained failures in a
     * second pool, and models the rows afterward. Results come back in spec
     * order, each the exact shape author() returns; a per-spec failure is
     * isolated (ok:false) and never aborts the batch. Slugs stay unique across
     * the batch AND against the passed manifest — each authored object grows the
     * draft the next one dedups against, exactly as the serial path did.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  array<string, mixed>  $manifest  current draft (slug uniqueness)
     * @return list<array{ok: bool, object?: array<string, mixed>, rows?: list<array<string, mixed>>, summary?: string, error?: string}>
     */
    public function authorMany(User $user, Integration $integration, array $specs, array $manifest): array
    {
        if ($specs === []) {
            return [];
        }

        $config = $this->integrationConfig($integration);

        // 1. Resolve each spec to a concrete call. The catalog lookup is
        //    memoized, so the whole batch shares one tools/list.
        $resolved = [];
        foreach ($specs as $i => $spec) {
            $resolved[$i] = $this->resolveCall($user, $integration, $spec);
        }

        // 2. Pool the current-window fetch for every spec that resolved.
        $calls = [];
        foreach ($resolved as $i => $r) {
            if (($r['ok'] ?? false) === true) {
                $calls[(string) $i] = ['name' => $r['tool_name'], 'arguments' => $r['arguments']];
            }
        }
        $pool = $this->pooledFetch($config, $user, $calls);

        // 3. A failed read may be a date constraint the schema doesn't declare
        //    (author()'s retry, batched): refire those with a synthesized
        //    window, and remember the shifted args for the authored object.
        $retryCalls = [];
        foreach ($resolved as $i => $r) {
            if (($r['ok'] ?? false) !== true || $this->poolSucceeded($pool[(string) $i] ?? null)) {
                continue;
            }
            $withDates = $this->fillDateArguments($r['arguments'], $r['input_schema']);
            if ($withDates !== $r['arguments']) {
                $retryCalls[(string) $i] = ['name' => $r['tool_name'], 'arguments' => $withDates];
                $resolved[$i]['arguments'] = $withDates;
            }
        }
        $retry = $this->pooledFetch($config, $user, $retryCalls);

        // 4. Model each result IN ORDER against a manifest that grows with every
        //    authored object, so slugs stay unique exactly as the serial path.
        $out = [];
        foreach ($specs as $i => $spec) {
            $r = $resolved[$i];
            if (($r['ok'] ?? false) !== true) {
                $out[] = $r; // resolve failed — carries the error verbatim

                continue;
            }
            $result = $retry[(string) $i] ?? $pool[(string) $i] ?? null;
            if (! $this->poolSucceeded($result)) {
                $out[] = ['ok' => false, 'error' => (is_array($result) ? ($result['error'] ?? null) : null)
                    ?? "The MCP tool '{$r['tool_name']}' did not return JSON rows."];

                continue;
            }
            $authored = $this->authorFromDecoded($integration, $spec, $r, $result['data'] ?? null, $manifest);
            $out[] = $authored;
            if (($authored['ok'] ?? false) === true) {
                $manifest['objects'][] = $authored['object'];
            }
        }

        return $out;
    }

    /**
     * Pool a set of tool calls, never throwing: a setup-level failure (session
     * handshake, auth, SSRF) degrades every call to ok:false — the same outcome
     * the serial reads would reach against an unreachable endpoint — instead of
     * aborting the batch.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, array{name: string, arguments: array<string, mixed>}>  $calls
     * @return array<string, array{ok: bool, data?: array<string, mixed>|null, error?: string}>
     */
    private function pooledFetch(array $config, User $user, array $calls): array
    {
        if ($calls === []) {
            return [];
        }
        try {
            return $this->mcp->poolToolCalls($config, $user, $calls);
        } catch (\Throwable $e) {
            return array_map(fn (): array => ['ok' => false, 'error' => $e->getMessage()], $calls);
        }
    }

    private function poolSucceeded(mixed $result): bool
    {
        return is_array($result) && ($result['ok'] ?? false) === true;
    }

    /**
     * Resolve a spec to a concrete MCP call — the tool from the server catalog,
     * arguments clamped to the input_schema and required params filled — WITHOUT
     * fetching. Split out so authorMany() can resolve many specs, pool their
     * fetches, then model; author() composes it with one fetch. ok:false carries
     * the same errors author() returned inline (missing tool_name, unknown tool,
     * a catalog/clamp failure).
     *
     * @param  array{tool_name?: string, arguments?: array<string, mixed>}  $spec
     * @return array{ok: bool, tool_name?: string, config?: array<string, mixed>, arguments?: array<string, mixed>, input_schema?: array<string, mixed>, clamped?: array<string, mixed>, error?: string}
     */
    private function resolveCall(User $user, Integration $integration, array $spec): array
    {
        $toolName = trim((string) ($spec['tool_name'] ?? ''));
        if ($toolName === '') {
            return ['ok' => false, 'error' => '`tool_name` is required.'];
        }

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
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'tool_name' => $toolName,
            'config' => $this->integrationConfig($integration),
            'arguments' => $arguments,
            'input_schema' => $tool['input_schema'],
            'clamped' => $clamped,
        ];
    }

    /**
     * Model an already-fetched tool result into a connected object — the second
     * half of author(), shared with authorMany() so a pooled batch and a single
     * call produce identical objects. $decoded is what callToolData /
     * poolToolCalls return; $resolved comes from resolveCall().
     *
     * @param  array<string, mixed>  $spec
     * @param  array{tool_name: string, arguments: array<string, mixed>, clamped: array<string, mixed>}  $resolved
     * @param  array<string, mixed>|null  $decoded
     * @param  array<string, mixed>  $manifest  current draft (slug uniqueness)
     * @return array{ok: bool, object?: array<string, mixed>, rows?: list<array<string, mixed>>, clamped?: array<string, mixed>, date_field_ids?: list<string>, derived_rates?: mixed, immature_periods?: mixed, summary?: string, error?: string}
     */
    private function authorFromDecoded(Integration $integration, array $spec, array $resolved, ?array $decoded, array $manifest): array
    {
        $toolName = $resolved['tool_name'];
        $arguments = $resolved['arguments'];
        $clamped = $resolved['clamped'];

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

        // Stamp the proven identity onto the rate field itself. Telling the model
        // "never average this" is advice, and advice loses to an instinct: the
        // first build after that guidance shipped still put avg(otd_pct) in the
        // hero. Written into the manifest, the identity outlives the conversation
        // and the validator can REFUSE the wrong aggregation instead of asking
        // nicely for the right one.
        $identities = app(RatioIdentity::class)->detect($object, $rows);
        $object['fields'] = $this->stampDerivedRates($object['fields'], $identities);

        // The tail of a live source has not RESOLVED yet, and read literally it is a
        // catastrophe: 0 delivered of 67, every day. This is the moment to say so —
        // the model is about to chart it and title the chart "collapse since 9 Jul".
        $maturation = app(MaturationCheck::class)->detect($object, $rows, $identities);

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
            // A rate column the sampled rows PROVE is derived from others. The model
            // is about to build a KPI out of it, and its instinct is avg() — which
            // for a derived rate is a different number, not an approximation. This
            // is the one moment it can be told, with the arithmetic to back it.
            'derived_rates' => $this->describeRates($identities),
            'immature_periods' => $this->describeMaturation($maturation),
            'summary' => "Creé el objeto conectado «{$name}» (live desde {$toolName})",
        ];
    }

    /**
     * The trailing periods that have not resolved, stated before the model charts them.
     *
     * @param  list<array<string, mixed>>  $maturation
     * @return list<array<string, mixed>>
     */
    private function describeMaturation(array $maturation): array
    {
        return collect($maturation)->map(function (array $m): array {
            $rate = (string) $m['rate']['name'];
            $n = (int) $m['immature_periods'];

            return [
                'rate_field_id' => $m['rate']['id'],
                'immature_periods' => $n,
                'evidence' => "only {$m['tail_resolved_pct']}% of {$m['denominator']['name']} has any outcome in the last {$n} periods, against {$m['baseline_resolved_pct']}% normally"
                    .($m['conclusive'] ? ' — and zero of them are late, which a real collapse never produces' : ''),
                'rate_over_full_window' => $m['full_window_rate'],
                'rate_over_resolved_window' => $m['mature_rate'],
                'guidance' => "The last {$n} periods of «{$rate}» have NOT HAPPENED YET — they are not a collapse, they are data that has not matured. Charting them reports a catastrophe that did not occur ({$m['full_window_rate']}% over the raw window vs {$m['mature_rate']}% over what actually resolved). Filter them out of every KPI and chart (`lt` the cutoff on the date field), and NEVER title a block or write an insight about a 'drop' or 'collapse' at the end of the series — that drop is the calendar, not the business.",
            ];
        })->values()->all();
    }

    /**
     * Record each proven identity on the rate field it belongs to, so the fact
     * survives into the manifest and the validator can enforce it on every future
     * edit — not just on the turn that discovered it.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $identities
     * @return list<array<string, mixed>>
     */
    private function stampDerivedRates(array $fields, array $identities): array
    {
        $byRate = collect($identities)->keyBy(fn (array $i): string => (string) $i['rate']['id']);

        return collect($fields)->map(function (array $field) use ($byRate): array {
            $identity = $byRate->get((string) ($field['id'] ?? ''));
            if ($identity === null) {
                return $field;
            }

            $field['derived_rate'] = array_filter([
                'numerator_field_id' => $identity['numerator']['id'],
                'minus_field_id' => $identity['minus']['id'] ?? null,
                'denominator_field_id' => $identity['denominator']['id'],
                'verified_on_rows' => $identity['matched'],
            ], fn ($v) => $v !== null);

            return $field;
        })->values()->all();
    }

    /**
     * Rate columns whose value the sampled rows derive from other columns, stated
     * so the model cannot average them by accident.
     *
     * @param  list<array<string, mixed>>  $identities
     * @return list<array<string, mixed>>
     */
    private function describeRates(array $identities): array
    {
        return collect($identities)
            ->map(function (array $identity): array {
                $rate = (string) $identity['rate']['name'];
                $formula = $identity['minus'] !== null
                    ? "({$identity['numerator']['name']} - {$identity['minus']['name']}) / {$identity['denominator']['name']}"
                    : "{$identity['numerator']['name']} / {$identity['denominator']['name']}";

                return [
                    'rate_field_id' => $identity['rate']['id'],
                    'formula' => $formula,
                    'verified_on' => "{$identity['matched']}/{$identity['rows']} rows",
                    'true_rate_pct' => $identity['true_rate'],
                    'averaged_rate_pct' => $identity['averaged_rate'],
                    'guidance' => $identity['expressible_as_kpi']
                        ? "NEVER aggregate «{$rate}» with avg or sum — the validator now REFUSES it, so a KPI built that way will not apply: the mean of per-row rates weights a small row like a big one (it reads {$identity['averaged_rate']}%, the true rate is {$identity['true_rate']}%). Build the KPI as a `stat` with aggregation sum on `{$identity['numerator']['id']}` and ratio_denominator {query, aggregation: sum, field_id: `{$identity['denominator']['id']}`} — the platform then recomputes SUM/SUM on every load. Charting «{$rate}» per row is fine; only the AGGREGATE is wrong."
                        : "NEVER aggregate «{$rate}» with avg or sum — the validator now REFUSES it, so a KPI built that way will not apply: it reads {$identity['averaged_rate']}%, the true rate is {$identity['true_rate']}%. Its numerator is a DIFFERENCE ({$formula}), and ratio_denominator can only point at a single column — so NO KPI on this source can state this rate honestly. Do not put it in a stat, metric_grid, gauge or hero. Chart it per row (that is correct), and show the components ({$identity['numerator']['name']}, {$identity['denominator']['name']}) as the KPIs instead.",
                ];
            })
            ->values()->all();
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
        $call = $this->previousWindowCall($object);
        if ($call === null) {
            return [];
        }

        try {
            $decoded = $this->mcp->callToolData($this->integrationConfig($integration), $user, $call['name'], $call['arguments']);
        } catch (\Throwable) {
            return [];
        }
        if ($decoded === null) {
            return [];
        }

        [$rows] = $this->extractRows($decoded, $call['collection_path']);

        return $rows;
    }

    /**
     * The previous-window read for MANY objects in ONE pooled round-trip — the
     * same span-back sample previousWindowRows() computes per object, but the
     * network latencies collapse to their max instead of their sum. Best-effort
     * per object: a tool with no window arg, or a failed read, simply yields no
     * delta (absent from the result), exactly as the serial path returns [].
     *
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, list<array<string, mixed>>> object id → previous rows
     */
    public function previousWindowRowsMany(User $user, Integration $integration, array $objects): array
    {
        $calls = [];
        $collectionPaths = [];
        foreach ($objects as $object) {
            $id = $object['id'] ?? null;
            $call = is_string($id) ? $this->previousWindowCall($object) : null;
            if ($id === null || $call === null) {
                continue;
            }
            $calls[$id] = ['name' => $call['name'], 'arguments' => $call['arguments']];
            $collectionPaths[$id] = $call['collection_path'];
        }
        if ($calls === []) {
            return [];
        }

        // Best-effort, exactly like the serial previousWindowRows: the current
        // window already succeeded and deltas are optional, so a setup-level
        // failure (session handshake, auth, SSRF guard) yields NO deltas rather
        // than failing the build. Per-object failures already come back as
        // ok:false inside the pool and are skipped below.
        try {
            $results = $this->mcp->poolToolCalls($this->integrationConfig($integration), $user, $calls);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($collectionPaths as $id => $collectionPath) {
            $result = $results[$id] ?? null;
            if (! is_array($result) || ($result['ok'] ?? false) !== true || ! is_array($result['data'] ?? null)) {
                continue;
            }
            [$rows] = $this->extractRows($result['data'], $collectionPath);
            if ($rows !== []) {
                $out[$id] = $rows;
            }
        }

        return $out;
    }

    /**
     * Build the previous-window tool call for one authored object — the tool
     * name, its arguments shifted one full span back, and the collection path —
     * or null when the object's tool carries no resolvable date window. Shared
     * by the single and pooled readers so both shift the window identically.
     *
     * @param  array<string, mixed>  $object
     * @return array{name: string, arguments: array<string, mixed>, collection_path: mixed}|null
     */
    private function previousWindowCall(array $object): ?array
    {
        $op = $object['source']['operations']['list'] ?? null;
        $toolName = trim((string) ($op['mcp_tool'] ?? ''));
        $arguments = is_array($op['arguments'] ?? null) ? $op['arguments'] : [];
        if ($toolName === '' || $arguments === []) {
            return null;
        }

        $fromKey = collect(ConnectedObjectReader::DATE_FROM_ARG_KEYS)->first(fn (string $k) => array_key_exists($k, $arguments));
        $toKey = collect(ConnectedObjectReader::DATE_TO_ARG_KEYS)->first(fn (string $k) => array_key_exists($k, $arguments));
        $from = $this->windowDate($arguments[$fromKey ?? ''] ?? null);
        if ($fromKey === null || $from === null) {
            return null;
        }
        $to = $this->windowDate($arguments[$toKey ?? ''] ?? null) ?? now()->utc()->startOfDay();

        $spanDays = max(1, (int) $from->diffInDays($to));
        $arguments[$fromKey] = $from->copy()->subDays($spanDays)->toDateString();
        if ($toKey !== null) {
            $arguments[$toKey] = $from->toDateString();
        }

        return ['name' => $toolName, 'arguments' => $arguments, 'collection_path' => $op['collection_path'] ?? null];
    }

    /**
     * The decrypted MCP config for an integration — the shape callToolData /
     * poolToolCalls expect. One place so the single and pooled readers can't
     * drift on how auth is resolved.
     *
     * @return array<string, mixed>
     */
    private function integrationConfig(Integration $integration): array
    {
        return [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];
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
