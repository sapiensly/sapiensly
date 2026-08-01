<?php

namespace App\Services\Platform;

use App\Models\AiCatalogModel;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageReport;
use App\Services\AiProviderService;
use App\Support\Ai\SpendPeriod;
use App\Support\Tenancy\Schemas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The real numbers behind the admin dashboard.
 *
 * Every figure here is measured, and each one names what it measured. Where the
 * platform genuinely does not record something, the section comes back null and
 * the screen shows its empty state — a dashboard that invents a plausible number
 * is worse than one that admits it has none, because you cannot tell which is
 * which by looking.
 *
 * Windows follow the labels the screen already uses: everything is the last 24
 * hours except the account total, with the delta measured against the 24 hours
 * before that, and a 12-point sparkline of two-hour buckets.
 *
 * Reads of tenant tables (conversations, the usage ledger) go through the owner
 * connection so the totals span every organization — the same rule as
 * {@see AiUsageReport::platformWide()}.
 */
class PlatformDashboard
{
    private const SPARKLINE_BUCKETS = 12;

    private const BUCKET_HOURS = 2;

    public function __construct(
        private readonly PlatformProbe $probe,
        private readonly AiProviderService $providers,
        private readonly AiDefaults $defaults,
        private readonly AiUsageReport $usage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $now = CarbonImmutable::now();

        // One report engine for money, the same one the tenant-facing spend
        // dashboard renders — read platform-wide here instead of RLS-scoped.
        // Two engines would drift, and the first symptom would be an
        // organization's own page disagreeing with the platform total.
        $report = $this->usage->platformWide(SpendPeriod::fromKey('today'));

        return [
            'stats' => $this->stats($now, $report),
            'services' => $this->services($report),
            'spend' => $this->spend($now),
            'health' => $this->health(),
            'audit' => $this->audit(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $report
     */
    private function stats(CarbonImmutable $now, array $report): ?array
    {
        $since = $now->subDay();
        $previous = $now->subDays(2);

        $resolved = $this->countConversations($since, $now, resolvedOnly: true);
        $resolvedBefore = $this->countConversations($previous, $since, resolvedOnly: true);

        $handling = $this->handlingTimes($since, $now);
        $handlingBefore = $this->handlingTimes($previous, $since);

        // Today's money comes from the shared report; yesterday's is measured
        // the same way, one window back, so the delta compares like with like.
        $today = [
            'cost' => (float) ($report['totals']['cost'] ?? 0),
            'calls' => (int) ($report['totals']['calls'] ?? 0),
            'tokens' => (int) ($report['totals']['input_tokens'] ?? 0) + (int) ($report['totals']['output_tokens'] ?? 0),
        ];
        $yesterday = $this->usageTotals($now->subDay()->startOfDay(), $now->startOfDay());

        $organizations = Organization::query()->count();

        return [
            'ticketsResolved' => [
                'value' => $resolved,
                'display' => number_format($resolved),
                'caption' => __('Conversations marked resolved'),
                'delta' => $this->delta($resolved, $resolvedBefore),
                'deltaDir' => 'up',
                'series' => $this->series(
                    $now,
                    fn (CarbonImmutable $from, CarbonImmutable $to) => $this->countConversations($from, $to, resolvedOnly: true),
                ),
            ],
            'avgHandleTime' => [
                'value' => $handling['avg_seconds'] ?? 0,
                'display' => $handling['avg_seconds'] === null
                    ? '—'
                    : number_format($handling['avg_seconds'], 1).'s',
                'caption' => $handling['p95_seconds'] === null
                    ? __('No response times recorded')
                    : __('p95 :value s', ['value' => number_format($handling['p95_seconds'], 1)]),
                // Faster is better, so a drop is the good direction here.
                'delta' => $this->delta($handling['avg_seconds'] ?? 0, $handlingBefore['avg_seconds'] ?? 0),
                'deltaDir' => 'down',
                'series' => $this->series(
                    $now,
                    fn (CarbonImmutable $from, CarbonImmutable $to) => round($this->handlingTimes($from, $to)['avg_seconds'] ?? 0, 2),
                ),
            ],
            'tokensUsed' => [
                'value' => $today['tokens'],
                'display' => $this->compact($today['tokens']),
                'caption' => __(':calls calls today', ['calls' => number_format($today['calls'])]),
                'delta' => $this->delta($today['tokens'], $yesterday['tokens']),
                'deltaDir' => 'up',
                'series' => $this->series(
                    $now,
                    fn (CarbonImmutable $from, CarbonImmutable $to) => $this->usageTotals($from, $to)['tokens'],
                ),
            ],
            'spendToday' => [
                'value' => $today['cost'],
                'display' => '$'.number_format($today['cost'], 2),
                'caption' => __('$:value month to date', [
                    'value' => number_format($this->usageTotals($now->startOfMonth(), $now)['cost'], 2),
                ]),
                'delta' => $this->delta($today['cost'], $yesterday['cost']),
                'deltaDir' => 'up',
                'series' => $this->series(
                    $now,
                    fn (CarbonImmutable $from, CarbonImmutable $to) => round($this->usageTotals($from, $to)['cost'], 4),
                ),
            ],
            'totalUsers' => [
                'value' => User::query()->count(),
                'display' => number_format(User::query()->count()),
                'caption' => trans_choice('{1} :count organization|[2,*] :count organizations', $organizations, [
                    'count' => number_format($organizations),
                ]),
            ],
        ];
    }

    /**
     * Where the platform's AI money went in the window, by service (Chat, Apps,
     * Chatbots, Knowledge, Landing Director…), each with the models underneath.
     *
     * This is the platform-wide read of the very breakdown an organization sees
     * on its own spend dashboard — same report, same shape, different scope —
     * so "what is this platform doing right now" is answered in the units the
     * bill is actually written in.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>|null
     */
    private function services(array $report): ?array
    {
        $services = $report['by_service'] ?? [];

        if ($services === []) {
            return null;
        }

        $total = (float) ($report['totals']['cost'] ?? 0);

        return array_map(static function (array $service) use ($total) {
            $models = array_slice($service['models'] ?? [], 0, 3);

            return [
                'service' => $service['service'],
                'cost' => round((float) $service['cost'], 4),
                'calls' => (int) $service['calls'],
                'tokens' => (int) $service['input_tokens'] + (int) $service['output_tokens'],
                // Share of the window's spend, so one line reads as a bar.
                'share' => $total > 0 ? round(((float) $service['cost'] / $total) * 100, 1) : 0.0,
                'models' => array_map(static fn (array $model) => [
                    'model' => $model['model'],
                    'cost' => round((float) $model['cost'], 4),
                    'calls' => (int) $model['calls'],
                    'tokens' => (int) $model['input_tokens'] + (int) $model['output_tokens'],
                    'unpriced' => ($model['unpriced'] ?? false) === true,
                ], $models),
            ];
        }, array_slice($services, 0, 6));
    }

    /**
     * Spend per provider over the last 24 hours, both ledgers combined.
     *
     * @return array<string, mixed>|null
     */
    private function spend(CarbonImmutable $now): ?array
    {
        $since = $now->subDay();

        $rows = [];
        foreach ($this->usageRows($since, $now) as $row) {
            $driver = (string) ($row->driver ?: 'unknown');
            $rows[$driver] ??= ['calls' => 0, 'cost' => 0.0];
            $rows[$driver]['calls']++;
            $rows[$driver]['cost'] += (float) $row->cost;
        }

        if ($rows === []) {
            return null;
        }

        uasort($rows, static fn (array $a, array $b) => $b['cost'] <=> $a['cost']);

        $palette = [
            'var(--sp-spectrum-magenta)',
            'var(--sp-spectrum-cyan)',
            'var(--sp-spectrum-indigo)',
            'var(--sp-accent-blue)',
        ];

        $providers = [];
        $index = 0;
        foreach ($rows as $driver => $totals) {
            $providers[] = [
                'name' => AiProviderService::DRIVER_LABELS[$driver] ?? ucfirst($driver),
                'calls' => $totals['calls'],
                'cost' => round($totals['cost'], 4),
                'color' => $palette[$index % count($palette)],
            ];
            $index++;
        }

        return ['providers' => $providers];
    }

    /**
     * Live checks, each one probed rather than assumed.
     *
     * @return list<array<string, mixed>>
     */
    private function health(): array
    {
        $now = now()->toIso8601String();
        $checks = [];

        $checks[] = $this->modelCheck('llm', __('Default LLM'), 'chat', $now);
        $checks[] = $this->modelCheck('embeddings', __('Embeddings'), 'embeddings', $now);

        $database = $this->probe->database();
        $checks[] = [
            'id' => 'db',
            'label' => __('Postgres'),
            'detail' => $database['version'] === null
                ? __('Unreachable')
                : trim(sprintf(
                    '%s · %s · %s',
                    $database['version'],
                    $database['pgvector'] === null ? __('no pgvector') : 'pgvector '.$database['pgvector'],
                    __(':used of :max connections', [
                        'used' => $database['connections'] ?? '?',
                        'max' => $database['max_connections'] ?? '?',
                    ]),
                )),
            'status' => match (true) {
                $database['version'] === null => 'error',
                $database['pgvector'] === null => 'warn',
                default => 'ok',
            },
            'lastCheckAt' => $now,
        ];

        $redis = $this->probe->redis();
        $checks[] = [
            'id' => 'redis',
            'label' => __('Redis'),
            'detail' => $redis['reachable']
                ? sprintf('%s · %s · %d %s', $redis['version'] ?? '?', $this->bytes((int) ($redis['used_bytes'] ?? 0)), (int) ($redis['clients'] ?? 0), __('clients'))
                : __('Unreachable'),
            'status' => $redis['reachable'] ? 'ok' : 'error',
            'lastCheckAt' => $now,
        ];

        $queues = $this->probe->queueDepths();
        $horizon = $this->probe->horizonRunning();
        $checks[] = [
            'id' => 'queue',
            'label' => __('Horizon queue'),
            'detail' => $horizon
                ? __(':pending pending · :failed failed', ['pending' => $queues['pending_total'], 'failed' => $queues['failed']])
                : __('Not running — queued work is not being processed'),
            'status' => match (true) {
                ! $horizon => 'error',
                $queues['failed'] > 0 => 'warn',
                default => 'ok',
            },
            'lastCheckAt' => $now,
        ];

        $reverb = $this->probe->reverbReachable();
        $checks[] = [
            'id' => 'reverb',
            'label' => __('Reverb (websockets)'),
            'detail' => $reverb ? __('Accepting connections') : __('Unreachable — live streaming will not reach browsers'),
            'status' => $reverb ? 'ok' : 'error',
            'lastCheckAt' => $now,
        ];

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private function modelCheck(string $id, string $label, string $module, string $now): array
    {
        $catalogId = $this->defaults->primaryId($module);
        $model = $catalogId === null ? null : AiCatalogModel::find($catalogId);

        if ($model === null) {
            $hard = $this->defaults->hardDefaultFor($module);

            return [
                'id' => $id,
                'label' => $label,
                'detail' => $hard === null
                    ? __('Not configured')
                    : __('Not configured — falling back to :model', ['model' => $hard]),
                'status' => $hard === null ? 'error' : 'warn',
                'lastCheckAt' => $now,
            ];
        }

        $connected = $this->providers->isDriverConfigured($model->driver);

        return [
            'id' => $id,
            'label' => $label,
            'detail' => sprintf(
                '%s · %s%s',
                AiProviderService::DRIVER_LABELS[$model->driver] ?? $model->driver,
                $model->model_id,
                $model->is_enabled ? '' : ' · '.__('disabled'),
            ),
            'status' => match (true) {
                ! $connected => 'error',
                ! $model->is_enabled => 'warn',
                default => 'ok',
            },
            'lastCheckAt' => $now,
        ];
    }

    /**
     * The most recent platform-administration writes, from the audit log.
     *
     * @return list<array<string, mixed>>
     */
    private function audit(int $limit = 8): array
    {
        try {
            $entries = PlatformAuditLog::query()
                ->with('actor:id,name')
                ->latest('created_at')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return [];
        }

        return $entries->map(function (PlatformAuditLog $entry) {
            $target = $entry->target_label ?? $entry->target_type ?? '—';
            [$verb, $rest] = $this->splitSummary($entry->summary, $entry->action);

            return [
                'id' => $entry->id,
                'icon' => $this->auditIcon($entry->action),
                'actor' => [
                    'id' => $entry->actor_user_id,
                    'name' => $entry->actor?->name ?? $entry->actor_email ?? __('System'),
                ],
                'action' => $verb,
                'target' => $target,
                'targetHref' => null,
                // Drop the detail line when it would only repeat the target.
                'context' => $rest !== null && mb_strtolower($rest) !== mb_strtolower($target) ? $rest : null,
                'at' => $entry->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Audit summaries are written verb-first ("Blocked ed@acme.com", "Rotated
     * the global anthropic API key"), so the row reads as a sentence once the
     * leading verb becomes the action and the remainder becomes the detail.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitSummary(?string $summary, string $action): array
    {
        $summary = trim((string) $summary);

        if ($summary === '') {
            return [str_replace('_', ' ', $action), null];
        }

        $parts = preg_split('/\s+/', $summary, 2);
        $verb = mb_strtolower($parts[0] ?? '');
        $rest = isset($parts[1]) ? trim($parts[1]) : null;

        return [$verb === '' ? str_replace('_', ' ', $action) : $verb, $rest === '' ? null : $rest];
    }

    private function auditIcon(string $action): string
    {
        return match (true) {
            str_contains($action, 'user') || str_contains($action, 'membership') => 'user',
            str_contains($action, 'provider') || str_contains($action, 'catalog') || str_contains($action, 'defaults') => 'sliders',
            str_contains($action, 'maintenance') || str_contains($action, 'job') => 'refresh',
            str_contains($action, 'organization') => 'library',
            default => 'eye',
        };
    }

    // ── measurement helpers ─────────────────────────────────────────────

    /**
     * Conversations opened in the window, or resolved in it. There is no
     * `resolved_at` column, so a resolution is dated by the row's last write —
     * close enough for a 24h roll-up, and stated here rather than implied.
     */
    private function countConversations(CarbonImmutable $from, CarbonImmutable $to, bool $resolvedOnly = false): int
    {
        try {
            $query = DB::connection('pgsql')->table(Schemas::qualify('widget_conversations'));

            if ($resolvedOnly) {
                $query->where('is_resolved', true)->whereBetween('updated_at', [$from, $to]);
            } else {
                $query->whereBetween('created_at', [$from, $to]);
            }

            return (int) $query->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{avg_seconds: ?float, p95_seconds: ?float}
     */
    private function handlingTimes(CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $row = DB::connection('pgsql')
                ->table(Schemas::qualify('widget_conversations'))
                ->whereBetween('created_at', [$from, $to])
                ->whereNotNull('total_response_time_ms')
                ->selectRaw('avg(total_response_time_ms) as avg_ms, percentile_cont(0.95) within group (order by total_response_time_ms) as p95_ms')
                ->first();
        } catch (Throwable) {
            return ['avg_seconds' => null, 'p95_seconds' => null];
        }

        return [
            'avg_seconds' => $row?->avg_ms === null ? null : round(((float) $row->avg_ms) / 1000, 2),
            'p95_seconds' => $row?->p95_ms === null ? null : round(((float) $row->p95_ms) / 1000, 2),
        ];
    }

    /**
     * @return array{cost: float, calls: int, tokens: int}
     */
    private function usageTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cost = 0.0;
        $calls = 0;
        $tokens = 0;

        foreach ($this->usageRows($from, $to) as $row) {
            $cost += (float) $row->cost;
            $calls++;
            $tokens += (int) $row->input_tokens + (int) $row->output_tokens;
        }

        return ['cost' => round($cost, 4), 'calls' => $calls, 'tokens' => $tokens];
    }

    /**
     * Both ledgers over a window: tenant BYOK spend and platform system spend.
     *
     * @return list<object>
     */
    private function usageRows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        foreach ([Schemas::qualify('ai_usage_events'), 'platform.system_ai_usage_events'] as $table) {
            try {
                $rows = array_merge($rows, DB::connection('pgsql')->table($table)
                    ->whereBetween('created_at', [$from, $to])
                    ->select('driver', 'cost', 'input_tokens', 'output_tokens')
                    ->get()
                    ->all());
            } catch (Throwable) {
                // Same as above: an unreadable ledger contributes nothing.
            }
        }

        return $rows;
    }

    /**
     * A 12-point sparkline of two-hour buckets ending now.
     *
     * @param  callable(CarbonImmutable, CarbonImmutable): (int|float)  $measure
     * @return list<int|float>
     */
    private function series(CarbonImmutable $now, callable $measure): array
    {
        $points = [];

        for ($bucket = self::SPARKLINE_BUCKETS; $bucket > 0; $bucket--) {
            $to = $now->subHours(($bucket - 1) * self::BUCKET_HOURS);
            $from = $to->subHours(self::BUCKET_HOURS);
            $points[] = $measure($from, $to);
        }

        return $points;
    }

    private function delta(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            // No baseline is not the same as "no change" and definitely not
            // "+100%"; the card simply shows no delta.
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function compact(int $value): string
    {
        return match (true) {
            $value >= 1_000_000_000 => round($value / 1_000_000_000, 1).'B',
            $value >= 1_000_000 => round($value / 1_000_000, 1).'M',
            $value >= 1_000 => round($value / 1_000, 1).'K',
            default => (string) $value,
        };
    }

    private function bytes(int $value): string
    {
        return match (true) {
            $value >= 1_073_741_824 => round($value / 1_073_741_824, 1).' GB',
            $value >= 1_048_576 => round($value / 1_048_576, 1).' MB',
            $value >= 1024 => round($value / 1024, 1).' KB',
            default => $value.' B',
        };
    }
}
