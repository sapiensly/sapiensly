<?php

namespace App\Services\Connected;

use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationCaller;
use App\Services\Records\ExpressionResolver;
use App\Services\Tools\McpClient;
use Illuminate\Support\Arr;

/**
 * Runtime read path for connected objects (builder power #2). Given a manifest
 * object whose `source` is `connected`, it lists rows live from the external
 * system through the integration and maps them to the object's fields via
 * `field_map` — partial-tolerant. Passthrough: it stores NOTHING in our database.
 * Provider-agnostic (REST endpoints and MCP tools alike); reads are remote/
 * may-fail and degrade to an error result rather than throwing.
 */
class ConnectedObjectReader
{
    /**
     * Upper bound on an MCP list result read into memory. Generous enough for a
     * real dashboard dataset, capped so a runaway tool response can't OOM the
     * worker (the failure the builder's row-by-row seeding used to hit).
     */
    private const MCP_MAX_CHARS = 2_000_000;

    /**
     * Per-instance memo of list() results. A dashboard page resolves a dozen
     * blocks over the SAME connected object with the same operation — without
     * this every KPI and chart re-fetched the identical external list (13 MCP
     * round-trips per page view). Keyed by everything that changes the raw
     * read: object, integration, operation + pushed-down params, and the acting
     * user. The instance lives for one request, so the memo can't go stale
     * across requests or leak between viewers.
     *
     * @var array<string, array{ok: bool, rows: list<array<string, mixed>>, error?: string}>
     */
    private array $memo = [];

    public function __construct(
        private readonly IntegrationCaller $caller,
        private readonly McpClient $mcp,
        private readonly ExpressionResolver $expressions,
    ) {}

    /**
     * @param  array<string, mixed>  $object  a manifest object_definition with source.type === 'connected'
     * @param  array<string, mixed>  $query  the block's data-source query (filter/sort/limit/offset), pushed
     *                                       down to the external API's params where the list operation declares
     *                                       the mapping — unmapped capabilities degrade gracefully (no-op).
     * @param  array<string, mixed>  $context  render context (params) — lets a date-range preset drive the
     *                                         source's start-date argument (best-effort push-down for MCP).
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    public function list(array $object, Integration $integration, array $query = [], ?User $actor = null, array $context = []): array
    {
        $source = $object['source'] ?? [];
        $op = $source['operations']['list'] ?? null;

        if (! is_array($op)) {
            return ['ok' => false, 'rows' => [], 'error' => 'No list operation is configured for this object.'];
        }

        // An MCP-backed connected object (e.g. a dashboard reading a support
        // desk live) calls a tool instead of a REST endpoint. The mapping
        // (field_map/id_path) and in-memory aggregation downstream are identical.
        if ($integration->is_mcp || ! empty($op['mcp_tool'])) {
            // Resolve {{…}} argument expressions against the request context, then
            // best-effort push the picked date-range window down into the tool's
            // start-date argument. The resolved arguments key the memo, so two
            // different presets don't collide on one cached read.
            $arguments = $this->resolveArguments(is_array($op['arguments'] ?? null) ? $op['arguments'] : [], $context);
            $arguments = $this->pushDownDateRange($arguments, $query, $context);
            $key = $this->memoKey($object, $integration, $op, $arguments, $actor);

            return $this->memo[$key] ??= $this->listViaMcp($object, $integration, $op, $source, $arguments, $actor);
        }

        if (empty($op['path'])) {
            return ['ok' => false, 'rows' => [], 'error' => 'No list operation is configured for this object.'];
        }

        $externalQuery = $this->buildExternalQuery($op, $source, $query);
        $key = $this->memoKey($object, $integration, $op, $externalQuery, $actor);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        try {
            $response = $this->caller->send(
                $integration,
                (string) ($op['method'] ?? 'GET'),
                (string) $op['path'],
                ['query' => $externalQuery],
            );
        } catch (\Throwable $e) {
            return $this->memo[$key] = ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return $this->memo[$key] = ['ok' => false, 'rows' => [], 'error' => "External system returned HTTP {$response->status()}."];
        }

        $json = $response->json() ?? [];
        $collectionPath = $op['collection_path'] ?? null;
        $raw = $collectionPath
            ? (array) Arr::get($json, $collectionPath, [])
            : (array) $json;

        $fieldSlugById = collect($object['fields'] ?? [])->pluck('slug', 'id')->all();
        $rows = array_map(
            fn ($row) => $this->mapRow((array) $row, $source, $fieldSlugById),
            array_values($raw),
        );

        return $this->memo[$key] = ['ok' => true, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $externalQuery
     */
    private function memoKey(array $object, Integration $integration, array $op, array $externalQuery, ?User $actor): string
    {
        return md5(json_encode([
            $object['id'] ?? $object['slug'] ?? '',
            $integration->id,
            $op,
            $externalQuery,
            $actor?->id,
        ]));
    }

    /**
     * List rows from an MCP integration: call the operation's `mcp_tool` (with
     * its static `arguments`) as the acting viewer — a per-user OAuth server
     * reads with that member's token — decode the structured result (tolerant of
     * structuredContent / JSON-in-text framing), extract the row array via
     * `collection_path`, and map each through the shared field_map/id_path. The
     * data-source query (filter/sort/paging) is NOT pushed down — an MCP tool
     * has no generic param surface — so pass a server-side limit in `arguments`
     * for large sources; aggregation runs over what the tool returns.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $arguments  already resolved + range-pushed by list()
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    private function listViaMcp(array $object, Integration $integration, array $op, array $source, array $arguments, ?User $actor = null): array
    {
        $toolName = trim((string) ($op['mcp_tool'] ?? ''));
        if ($toolName === '') {
            return ['ok' => false, 'rows' => [], 'error' => 'No MCP tool is configured for this object\'s list operation.'];
        }

        $config = [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            // Auth resolves against the acting viewer where given: a per-user
            // OAuth MCP (e.g. YuhuGo) reads with THAT user's token; static /
            // service schemes pass through auth_config and ignore the user.
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];

        try {
            $decoded = $this->mcp->callToolData($config, $actor, $toolName, $arguments, self::MCP_MAX_CHARS);
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $this->readableAuthError($e->getMessage())];
        }

        if ($decoded === null) {
            return ['ok' => false, 'rows' => [], 'error' => 'The MCP tool did not return JSON rows.'];
        }

        $collectionPath = $op['collection_path'] ?? null;
        $raw = $collectionPath ? (array) Arr::get($decoded, $collectionPath, []) : $decoded;

        // A single object (not a list) → treat it as one row.
        if ($raw !== [] && Arr::isAssoc($raw)) {
            $raw = [$raw];
        }

        $fieldSlugById = collect($object['fields'] ?? [])->pluck('slug', 'id')->all();
        $rows = array_map(
            fn ($row) => $this->mapRow((array) $row, $source, $fieldSlugById),
            array_values($raw),
        );

        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * Argument keys a start-date window pushes down onto. A live list tool names
     * its lower date bound in one of a few conventional ways; we override that
     * one key so the dashboard's date-range preset drives the actual fetch. Kept
     * to unambiguous START-of-window names (never a bare `date`/`to`/`end`).
     */
    private const DATE_FROM_ARG_KEYS = [
        'from', 'from_date', 'date_from', 'start', 'start_date', 'since',
        'after', 'desde', 'fecha_inicio', 'fecha_desde',
    ];

    /**
     * Resolve {{…}} expression strings inside operation arguments (recursively)
     * against the render context — clock/pure functions plus params (so a
     * range-tied argument like {{range_start(default(params.range,'1y'))}} the
     * compiler wired resolves to the picked preset, not an empty default).
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveArguments(array $arguments, array $context = []): array
    {
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $arguments[$key] = $this->resolveArguments($value, $context);
            } elseif (is_string($value) && str_contains($value, '{{')) {
                $arguments[$key] = $this->expressions->resolve($value, $context);
            }
        }

        return $arguments;
    }

    /**
     * Best-effort: widen (or narrow) the MCP source's FETCH window to the
     * dashboard's active date-range preset. A connected object bakes its own
     * fetch window into the tool arguments at build time (e.g. `from:
     * {{days_ago(183)}}`), so the in-memory date filter can only ever TRIM what
     * that fixed window returned — picking "Año" over a 6-month bake shows
     * nothing new. Here we read the window the block's own date filter asks for
     * (its {{range_start(…)}} leaf, resolved against the request params) and, if
     * the tool exposes a start-date argument, override it with that date. So the
     * preset the viewer picks re-fetches the matching span at the source.
     *
     * No-ops (arguments returned unchanged) when: the block has no range-start
     * filter, the preset resolves empty, or the tool has no start-date argument
     * — in which case the baked window and the in-memory trim stand as before.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $query  the block's data-source query
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function pushDownDateRange(array $arguments, array $query, array $context): array
    {
        $expr = $this->findRangeStartExpression($query['filter'] ?? null);
        if ($expr === null) {
            return $arguments;
        }

        $start = $this->expressions->resolve($expr, $context);
        if (! is_string($start) || trim($start) === '') {
            return $arguments; // empty preset ⇒ leave the source's own widest window
        }

        foreach (self::DATE_FROM_ARG_KEYS as $key) {
            if (array_key_exists($key, $arguments)) {
                $arguments[$key] = $start;

                return $arguments;
            }
        }

        return $arguments;
    }

    /**
     * Find the {{range_start(…)}} value_expression the compiler wired as the
     * date-range gte leaf, walking and/or/not groups. Returns the raw expression
     * string (resolved by the caller against the live params), or null when the
     * filter carries no range-start window.
     */
    private function findRangeStartExpression(mixed $filter): ?string
    {
        if (! is_array($filter)) {
            return null;
        }

        $op = $filter['op'] ?? null;
        if (in_array($op, ['and', 'or', 'not'], true)) {
            foreach ($filter['conditions'] ?? [] as $condition) {
                $found = $this->findRangeStartExpression($condition);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        $expr = $filter['value_expression'] ?? null;
        if (is_string($expr) && str_contains($expr, 'range_start')) {
            return $expr;
        }

        return null;
    }

    /**
     * Turn a raw MCP auth failure into a viewer-facing reason. A per-user OAuth
     * source (no/expired token for THIS viewer) is the common case — the block
     * error card then tells them to authorize the connection instead of showing
     * the internal "requires a user context to resolve the token".
     */
    private function readableAuthError(string $raw): string
    {
        $lower = mb_strtolower($raw);
        if (str_contains($lower, 'user context')
            || str_contains($lower, 'resolve the token')
            || str_contains($lower, 're-authorize')
            || str_contains($lower, 'reauthorize')
            || str_contains($lower, 'unauthorized')) {
            return 'This live source needs you to authorize the connection — open the integration and connect it, then reload.';
        }

        return $raw;
    }

    /**
     * Translate the block's data-source query into the external API's query
     * params, driven entirely by the list operation's declared mappings — the
     * names are manifest data, never per-provider code. Anything the operation
     * doesn't declare a mapping for (e.g. a non-equality filter, an unmapped
     * sort field) is simply not pushed down: the read degrades gracefully
     * rather than failing. Passthrough is preserved — we only shape the request.
     *
     * @param  array<string, mixed>  $op  the list source_operation
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $query  data-source query (filter/sort/limit/offset)
     * @return array<string, mixed>
     */
    private function buildExternalQuery(array $op, array $source, array $query): array
    {
        $params = [];

        $limit = $query['limit'] ?? null;
        $offset = $query['offset'] ?? null;

        if (! empty($op['page_size_param']) && $limit !== null) {
            $params[$op['page_size_param']] = (int) $limit;
        }

        if (! empty($op['page_param']) && $offset !== null) {
            $params[$op['page_param']] = ($op['page_mode'] ?? 'offset') === 'page'
                ? intdiv((int) $offset, max((int) ($limit ?? 1), 1)) + 1
                : (int) $offset;
        }

        $sort = $query['sort'][0] ?? null;
        if (is_array($sort) && ! empty($op['sort_param']) && ! empty($sort['field_id'])) {
            $externalName = $this->externalPathFor($source, (string) $sort['field_id']);
            if ($externalName !== null) {
                $params[$op['sort_param']] = $externalName;
                if (! empty($op['order_param'])) {
                    $params[$op['order_param']] = ($sort['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                }
            }
        }

        $filterParams = [];
        foreach ($op['filter_params'] ?? [] as $fp) {
            if (is_array($fp) && ! empty($fp['field_id']) && ! empty($fp['param'])) {
                $filterParams[$fp['field_id']] = $fp['param'];
            }
        }
        if ($filterParams !== [] && isset($query['filter']) && is_array($query['filter'])) {
            $eq = [];
            $this->collectEqConditions($query['filter'], $eq);
            foreach ($eq as $fieldId => $value) {
                if (isset($filterParams[$fieldId]) && (is_scalar($value) || $value === null)) {
                    $params[$filterParams[$fieldId]] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Collect the literal-valued equality conditions a flat AND tree exposes —
     * the only filter shape we can faithfully push down to a single query param
     * each. Other operators (or/not/gt/contains/value_expression) are skipped:
     * the external API can't express them through this mapping, so they degrade
     * to no-op rather than being silently mistranslated.
     *
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $out  field_id => value (by reference)
     */
    private function collectEqConditions(array $expr, array &$out): void
    {
        $op = $expr['op'] ?? null;

        if ($op === 'and') {
            foreach ($expr['conditions'] ?? [] as $cond) {
                if (is_array($cond)) {
                    $this->collectEqConditions($cond, $out);
                }
            }

            return;
        }

        if ($op === 'eq' && ! empty($expr['field_id']) && array_key_exists('value', $expr)) {
            $out[$expr['field_id']] = $expr['value'];
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function externalPathFor(array $source, string $fieldId): ?string
    {
        foreach ($source['field_map'] ?? [] as $entry) {
            if (($entry['field_id'] ?? null) === $fieldId) {
                return $entry['external_path'] ?? null;
            }
        }

        return null;
    }

    /**
     * Map one external row to manifest field slugs, plus the external id under
     * `_external_id` for later read/write addressing. Partial-tolerant.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $fieldSlugById
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $source, array $fieldSlugById): array
    {
        $mapped = [];
        foreach (($source['field_map'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['field_id']) || ! isset($entry['external_path'])) {
                continue;
            }
            $slug = $fieldSlugById[$entry['field_id']] ?? $entry['field_id'];
            $mapped[$slug] = Arr::get($row, $entry['external_path']);
        }

        if (! empty($source['id_path'])) {
            $mapped['_external_id'] = Arr::get($row, $source['id_path']);
        }

        return $mapped;
    }
}
