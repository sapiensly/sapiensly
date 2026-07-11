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

    /**
     * Per-request memo of each MCP endpoint's tool input-schema properties (name
     * → property schema), keyed by config+viewer then tool name — one tools/list
     * per source, reused across every block's date-range/granularity push-down.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $toolSchemas = [];

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
            [$config, $arguments, $key] = $this->mcpPlan($object, $integration, $op, $query, $actor, $context);
            if (isset($this->memo[$key])) {
                return $this->memo[$key];
            }

            $result = $this->listViaMcp($object, $integration, $op, $source, $arguments, $config, $actor);
            // A widened window can overshoot a tool's own max range ("El rango
            // máximo permitido es de 92 días") — the push-down asked for more than
            // the source allows. Cap to the tool's stated maximum and retry once
            // so the chart renders its widest legal window instead of an error.
            $result = $this->retryWithinMaxRange($result, $arguments, $context, $object, $integration, $op, $source, $config, $actor);
            // A hand-authored object can omit a window the tool REQUIRES
            // ("The from field is required.") — synthesize the default window
            // and retry once, mirroring what authoring does from the schema.
            $result = $this->retryWithRequiredWindow($result, $arguments, $context, $object, $integration, $op, $source, $config, $actor);

            return $this->memo[$key] = $result;
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
     * The McpClient config for an integration: endpoint + auth. Auth resolves
     * against the acting viewer where given (a per-user OAuth MCP reads with
     * THAT user's token; static/service schemes pass through auth_config).
     *
     * @return array<string, mixed>
     */
    private function mcpConfig(Integration $integration): array
    {
        return [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];
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
     * @param  array<string, mixed>  $config  the MCP client config (from mcpConfig)
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    /**
     * The fully-resolved shape of one MCP read: config, arguments ({{…}}
     * resolved, window pushed down / shifted) and the memo key. Extracted so
     * list() and prefetch() compute IDENTICAL keys — a prefetch that resolves
     * arguments differently would warm nothing.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function mcpPlan(array $object, Integration $integration, array $op, array $query, ?User $actor, array $context): array
    {
        $config = $this->mcpConfig($integration);
        // Resolve {{…}} argument expressions against the request context, then
        // best-effort push the picked date-range window down into the tool's
        // start-date argument. The resolved arguments key the memo, so two
        // different presets don't collide on one cached read.
        $arguments = $this->resolveArguments(is_array($op['arguments'] ?? null) ? $op['arguments'] : [], $context);
        $arguments = $this->pushDownDateRange($arguments, $query, $context, $op, $config, $actor);
        if (($context['__window'] ?? null) === 'previous') {
            // A KPI's live previous-window compare: same tool, the RESOLVED
            // window (authored or picker-pushed) shifted back one span.
            $arguments = $this->shiftWindowToPrevious($arguments, $context);
        }

        return [$config, $arguments, $this->memoKey($object, $integration, $op, $arguments, $actor)];
    }

    /**
     * Warm the memo for a page's DISTINCT MCP reads concurrently. A complex
     * dashboard used to pay 7-11 serial round-trips before its first byte;
     * pooling turns the sum into the max. Failures are left un-memoized so
     * the serial path keeps its retry ladder (max-range, required-window);
     * prefetch can only make a page faster, never break it.
     *
     * @param  list<array{object: array<string, mixed>, integration: Integration, query: array<string, mixed>, actor: ?User, context: array<string, mixed>}>  $reads
     */
    public function prefetch(array $reads): void
    {
        $groups = [];
        foreach ($reads as $read) {
            $object = $read['object'];
            $integration = $read['integration'];
            $source = $object['source'] ?? [];
            $op = $source['operations']['list'] ?? null;
            if (! is_array($op) || ! ($integration->is_mcp || ! empty($op['mcp_tool']))) {
                continue;
            }
            $toolName = trim((string) ($op['mcp_tool'] ?? ''));
            if ($toolName === '') {
                continue;
            }
            try {
                [$config, $arguments, $key] = $this->mcpPlan($object, $integration, $op, $read['query'], $read['actor'], $read['context']);
            } catch (\Throwable) {
                continue; // planning failed — the serial path will surface it
            }
            if (isset($this->memo[$key])) {
                continue;
            }
            $groupKey = $integration->id.'|'.($read['actor']?->id ?? '-');
            $groups[$groupKey] ??= ['config' => $config, 'actor' => $read['actor'], 'calls' => [], 'meta' => []];
            if (isset($groups[$groupKey]['calls'][$key])) {
                continue; // same read referenced by several blocks
            }
            $groups[$groupKey]['calls'][$key] = ['name' => $toolName, 'arguments' => $arguments];
            $groups[$groupKey]['meta'][$key] = ['object' => $object, 'op' => $op, 'source' => $source];
        }

        foreach ($groups as $group) {
            try {
                $results = $this->mcp->poolToolCalls($group['config'], $group['actor'], $group['calls'], self::MCP_MAX_CHARS);
            } catch (\Throwable) {
                continue; // handshake/transport failed — serial path decides
            }
            foreach ($results as $key => $result) {
                if (($result['ok'] ?? false) !== true || ! is_array($result['data'] ?? null)) {
                    continue;
                }
                $meta = $group['meta'][$key];
                $this->memo[$key] = [
                    'ok' => true,
                    'rows' => $this->mapMcpRows($meta['object'], $meta['op'], $meta['source'], $result['data']),
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function mapMcpRows(array $object, array $op, array $source, array $decoded): array
    {
        $collectionPath = $op['collection_path'] ?? null;
        $raw = $collectionPath ? (array) Arr::get($decoded, $collectionPath, []) : $decoded;

        // A single object (not a list) → treat it as one row.
        if ($raw !== [] && Arr::isAssoc($raw)) {
            $raw = [$raw];
        }

        $fieldSlugById = collect($object['fields'] ?? [])->pluck('slug', 'id')->all();

        return array_map(
            fn ($row) => $this->mapRow((array) $row, $source, $fieldSlugById),
            array_values($raw),
        );
    }

    private function listViaMcp(array $object, Integration $integration, array $op, array $source, array $arguments, array $config, ?User $actor = null): array
    {
        $toolName = trim((string) ($op['mcp_tool'] ?? ''));
        if ($toolName === '') {
            return ['ok' => false, 'rows' => [], 'error' => 'No MCP tool is configured for this object\'s list operation.'];
        }

        try {
            $decoded = $this->mcp->callToolData($config, $actor, $toolName, $arguments, self::MCP_MAX_CHARS);
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $this->readableAuthError($e->getMessage())];
        }

        if ($decoded === null) {
            return ['ok' => false, 'rows' => [], 'error' => 'The MCP tool did not return JSON rows.'];
        }

        return ['ok' => true, 'rows' => $this->mapMcpRows($object, $op, $source, $decoded)];
    }

    /**
     * Argument keys a start-date window pushes down onto. A live list tool names
     * its lower date bound in one of a few conventional ways; we override that
     * one key so the dashboard's date-range preset drives the actual fetch. Kept
     * to unambiguous START-of-window names (never a bare `date`/`to`/`end`).
     */
    public const DATE_FROM_ARG_KEYS = [
        'from', 'from_date', 'date_from', 'start', 'start_date', 'since',
        'after', 'desde', 'fecha_inicio', 'fecha_desde',
    ];

    /**
     * The mirror END-of-window argument names. Only ever set to "today" (never a
     * value from the block), so a widened fetch runs from the picked window
     * start through now.
     */
    public const DATE_TO_ARG_KEYS = [
        'to', 'to_date', 'date_to', 'end', 'end_date', 'until',
        'before', 'hasta', 'fecha_fin', 'fecha_hasta',
    ];

    /**
     * Argument names that select the source's time GRAIN. A short date-range
     * preset (today / 7d) wants DAILY resolution — a weekly series over 7 days
     * is a single bucket — so when the picked window is small we switch this
     * argument to the tool's daily value (schema-gated).
     */
    private const GRANULARITY_ARG_KEYS = [
        'granularity', 'granularidad', 'interval', 'intervalo',
        'period', 'periodo', 'frequency', 'frecuencia',
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
     * Best-effort: widen the MCP source's FETCH window to the dashboard's active
     * date-range preset. A connected object bakes its fetch window into the tool
     * arguments at build time — or, worse, OMITS a window entirely and rides the
     * tool's adaptive default (get-nps-time-series-tool defaults weekly to 12
     * buckets, so "Año" showed only ~13 rows). The in-memory date filter can
     * only TRIM what the source returned, never widen it.
     *
     * We read the window the block's own date filter asks for (its
     * {{range_start(…)}} leaf, resolved against the request params) and apply it
     * to the tool's start-date argument two ways:
     *   1. OVERRIDE — the object already sets a start-date arg (from/desde/…):
     *      replace its value with the picked window start.
     *   2. INJECT — the object sets none, but the tool's input SCHEMA declares
     *      one: add it (plus the mirror end-date = today). Schema-gated so we
     *      never send a parameter a strict tool would reject; if the schema
     *      can't be read we simply skip injection.
     *
     * The window expression comes from the block's own date filter when it has
     * one; a block with NONE (its object exposes no date field to filter by —
     * pre-aggregated breakdowns) falls back to the PAGE's date_range control,
     * threaded by BlockDataResolver as `__page_range_start_expr`. Without the
     * fallback those blocks stayed frozen on the authoring-time window while
     * their subtitles claimed "en la ventana" (prod yuhunps: two KPIs and two
     * hbars stuck at the tool's baked 30d whatever the picker said).
     *
     * No-ops when: neither the block nor the page carries a range expression,
     * the preset resolves empty, or neither an existing arg nor the schema
     * offers a start-date key.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $query  the block's data-source query
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $op  the list operation (carries mcp_tool)
     * @param  array<string, mixed>  $config  the MCP client config
     * @return array<string, mixed>
     */
    private function pushDownDateRange(array $arguments, array $query, array $context, array $op, array $config, ?User $actor): array
    {
        $expr = $this->findRangeStartExpression($query['filter'] ?? null)
            ?? (is_string($context['__page_range_start_expr'] ?? null) ? $context['__page_range_start_expr'] : null);
        if ($expr === null) {
            return $arguments;
        }

        $start = $this->expressions->resolve($expr, $context);
        if (! is_string($start) || trim($start) === '') {
            return $arguments; // empty preset ⇒ leave the source's own widest window
        }

        $toolName = trim((string) ($op['mcp_tool'] ?? ''));

        // 1) The fetch WINDOW: override an existing start-date argument, else
        //    inject one the tool declares but the object never set.
        $fromKey = $this->firstExistingKey(self::DATE_FROM_ARG_KEYS, $arguments);
        if ($fromKey !== null) {
            $arguments[$fromKey] = $start;
        } else {
            $props = $this->toolProperties($config, $actor, $toolName);
            $declaredFrom = $this->firstDeclared(self::DATE_FROM_ARG_KEYS, array_keys($props));
            if ($declaredFrom !== null) {
                $arguments[$declaredFrom] = $start;
                $declaredTo = $this->firstDeclared(self::DATE_TO_ARG_KEYS, array_keys($props));
                if ($declaredTo !== null && ! array_key_exists($declaredTo, $arguments)) {
                    $arguments[$declaredTo] = $this->expressions->resolve('{{today()}}', $context);
                }
            }
        }

        // 2) The GRAIN: a short window (Hoy / 7 días) wants DAILY resolution.
        return $this->adaptGranularity($arguments, $start, $context, $config, $actor, $toolName);
    }

    /**
     * A source can cap how wide a window it will serve ("El rango máximo
     * permitido es de 92 días"). When the pushed window overshot that, parse the
     * stated maximum from the error, recompute the start date to sit just inside
     * it, and retry ONCE — so a "1 año" preset over a 92-day source renders 92
     * days of data, not an error card. No-op when the error isn't a range cap,
     * carries no day count, or we didn't push a start-date argument.
     *
     * @param  array{ok: bool, rows: list<array<string, mixed>>, error?: string}  $result
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    private function retryWithinMaxRange(array $result, array $arguments, array $context, array $object, Integration $integration, array $op, array $source, array $config, ?User $actor): array
    {
        if (($result['ok'] ?? false) === true) {
            return $result;
        }
        $error = (string) ($result['error'] ?? '');
        if (preg_match('/m[áa]xim|permitid|l[íi]mite|limit|exceed|rango/iu', $error) !== 1
            || preg_match('/(\d+)\s*(d[íi]as|days)/iu', $error, $m) !== 1) {
            return $result;
        }
        $maxDays = (int) $m[1];
        $fromKey = $this->firstExistingKey(self::DATE_FROM_ARG_KEYS, $arguments);
        if ($maxDays < 1 || $fromKey === null) {
            return $result; // not a windowed call we drove
        }

        $today = $this->expressions->resolve('{{today()}}', $context);
        if (! is_string($today) || strtotime($today) === false) {
            return $result;
        }
        // Sit a day inside the stated max to absorb inclusive/exclusive counting.
        $capped = date('Y-m-d', (int) strtotime($today.' -'.max(1, $maxDays - 1).' days'));
        if ($capped === ($arguments[$fromKey] ?? null)) {
            return $result; // already at the cap — don't loop
        }
        $arguments[$fromKey] = $capped;

        return $this->listViaMcp($object, $integration, $op, $source, $arguments, $config, $actor);
    }

    /**
     * Shift a resolved from/to window one span back — [from-span, from] — for
     * the `__window: previous` compare read. No-op without a from key (the
     * compare then equals the current value and the chip reads flat, never
     * wrong) or when the dates do not parse.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function shiftWindowToPrevious(array $arguments, array $context): array
    {
        $fromKey = $this->firstExistingKey(self::DATE_FROM_ARG_KEYS, $arguments);
        if ($fromKey === null) {
            return $arguments;
        }
        $from = strtotime((string) $arguments[$fromKey]);

        $toKey = $this->firstExistingKey(self::DATE_TO_ARG_KEYS, $arguments);
        $todayResolved = $this->expressions->resolve('{{today()}}', $context);
        $to = $toKey !== null
            ? strtotime((string) $arguments[$toKey])
            : (is_string($todayResolved) ? strtotime($todayResolved) : false);

        if ($from === false || $to === false || $to <= $from) {
            return $arguments;
        }

        $span = max(86400, $to - $from);
        $arguments[$fromKey] = date('Y-m-d', $from - $span);
        if ($toKey !== null) {
            $arguments[$toKey] = date('Y-m-d', $from);
        }

        return $arguments;
    }

    /**
     * A tool can REQUIRE a date window the object never authored ("The from
     * field is required. The to field is required.") — observed when an agent
     * recreated a connected object by hand via propose_change and dropped the
     * args the auto-authoring synthesizes from the input schema. Parse the
     * missing field names out of the validation error, fill any that are
     * known window keys (from → 30 days ago, to → today, matching the
     * authoring default), and retry ONCE. No-op when the error isn't a
     * required-field complaint or none of the missing names is a window key —
     * a required `dimension` (etc.) stays the author's problem to fix.
     *
     * @param  array{ok: bool, rows: list<array<string, mixed>>, error?: string}  $result
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    private function retryWithRequiredWindow(array $result, array $arguments, array $context, array $object, Integration $integration, array $op, array $source, array $config, ?User $actor): array
    {
        if (($result['ok'] ?? false) === true) {
            return $result;
        }

        preg_match_all(
            '/(?:The |El campo )([a-z0-9_]+)(?: field is required| es (?:obligatorio|requerido))/iu',
            (string) ($result['error'] ?? ''),
            $matches,
        );
        $missing = array_map('strtolower', array_filter($matches[1] ?? []));
        if ($missing === []) {
            return $result;
        }

        $today = $this->expressions->resolve('{{today()}}', $context);
        $start = $this->expressions->resolve('{{days_ago(30)}}', $context);
        if (! is_string($today) || ! is_string($start)) {
            return $result;
        }

        $filled = false;
        foreach ($missing as $key) {
            if (array_key_exists($key, $arguments)) {
                continue;
            }
            if (in_array($key, self::DATE_FROM_ARG_KEYS, true)) {
                $arguments[$key] = $start;
                $filled = true;
            } elseif (in_array($key, self::DATE_TO_ARG_KEYS, true)) {
                $arguments[$key] = $today;
                $filled = true;
            }
        }
        if (! $filled) {
            return $result;
        }

        return $this->listViaMcp($object, $integration, $op, $source, $arguments, $config, $actor);
    }

    /**
     * When the picked window spans a week or less, switch the tool's granularity
     * argument to its DAILY value — a weekly series over 7 days is one bucket, so
     * a short range should ask the source for daily rows if it offers them. Only
     * fires when the tool's schema declares a granularity param AND exposes a
     * daily value for it ("check for a daily-frequency tool, then use it"); a
     * 30-day-plus window keeps the object's baked grain.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function adaptGranularity(array $arguments, string $start, array $context, array $config, ?User $actor, string $toolName): array
    {
        $today = $this->expressions->resolve('{{today()}}', $context);
        $span = $this->daysBetween($start, is_string($today) ? $today : null);
        if ($span === null || $span > 8) {
            return $arguments; // 30d+ keeps the source's baked grain
        }

        $props = $this->toolProperties($config, $actor, $toolName);
        $granKey = $this->firstDeclared(self::GRANULARITY_ARG_KEYS, array_keys($props));
        if ($granKey === null) {
            return $arguments;
        }

        $daily = $this->dailyGranularityValue(
            is_array($props[$granKey] ?? null) ? $props[$granKey] : [],
            $arguments[$granKey] ?? null,
        );
        if ($daily !== null) {
            $arguments[$granKey] = $daily;
        }

        return $arguments;
    }

    /**
     * The tool's DAILY granularity token. From the param's enum first (the only
     * safe source of the exact accepted value); failing an enum, the daily
     * sibling in the same LANGUAGE as the object's baked value. Null when the
     * tool has no daily option (an enum without one) or we can't tell — never
     * guess a token a strict tool might reject.
     *
     * @param  array<string, mixed>  $paramSchema
     */
    private function dailyGranularityValue(array $paramSchema, mixed $current): ?string
    {
        $enum = array_values(array_filter($paramSchema['enum'] ?? [], 'is_string'));
        if ($enum !== []) {
            foreach ($enum as $value) {
                if (preg_match('/^(dai|d[ií]a|day)/i', $value) === 1) {
                    return $value;
                }
            }

            return null; // enum present, no daily option ⇒ no daily frequency
        }

        if (! is_string($current)) {
            return null;
        }
        if (preg_match('/seman|mensual|anual|trimestr|d[ií]a/iu', $current) === 1) {
            return 'diario';
        }
        if (preg_match('/week|month|year|quarter|dai|day/i', $current) === 1) {
            return 'daily';
        }

        return null;
    }

    /**
     * Absolute day span between two YYYY-MM-DD-ish dates, or null if either is
     * unparseable.
     */
    private function daysBetween(string $from, ?string $to): ?int
    {
        if ($to === null || $to === '') {
            return null;
        }
        $a = strtotime($from);
        $b = strtotime($to);
        if ($a === false || $b === false) {
            return null;
        }

        return (int) round(abs($b - $a) / 86400);
    }

    /**
     * The first of $candidates that is a key of $arguments (exact), returned in
     * the arguments' own casing.
     *
     * @param  list<string>  $candidates
     * @param  array<string, mixed>  $arguments
     */
    private function firstExistingKey(array $candidates, array $arguments): ?string
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $arguments)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first of $candidates that appears in $declared (case-insensitive).
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $declared
     */
    private function firstDeclared(array $candidates, array $declared): ?string
    {
        $lower = array_map('strtolower', $declared);
        foreach ($candidates as $candidate) {
            $i = array_search(strtolower($candidate), $lower, true);
            if ($i !== false) {
                return $declared[$i]; // return the tool's own casing
            }
        }

        return null;
    }

    /**
     * The input-schema PROPERTIES a tool declares (name → property schema, so
     * callers can read enums), memoised per config+viewer for the request. A
     * tools/list round-trip that fails (auth, transport) degrades to [] — the
     * push-down is then skipped, never fatal.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function toolProperties(array $config, ?User $actor, string $toolName): array
    {
        if ($toolName === '') {
            return [];
        }
        $key = md5(json_encode([$config['integration_id'] ?? $config['endpoint'] ?? '', $actor?->id]));
        if (! isset($this->toolSchemas[$key])) {
            try {
                $tools = $this->mcp->listTools($config, $actor);
            } catch (\Throwable) {
                $tools = [];
            }
            $schemas = [];
            foreach ($tools as $tool) {
                $props = $tool['input_schema']['properties'] ?? null;
                $schemas[(string) ($tool['name'] ?? '')] = is_array($props) ? $props : [];
            }
            $this->toolSchemas[$key] = $schemas;
        }

        return $this->toolSchemas[$key][$toolName] ?? [];
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
