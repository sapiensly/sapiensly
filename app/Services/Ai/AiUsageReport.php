<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Support\Ai\SpendArtifact;
use App\Support\Ai\SpendPeriod;
use App\Support\Tenancy\Schemas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the AI spend dashboards. The org-facing view queries
 * ai_usage_events through the tenant connection (RLS auto-scopes it to the
 * caller's org); the platform view reads cross-org via the owner connection
 * (which bypasses RLS) so a sysadmin sees every organization at once.
 */
class AiUsageReport
{
    /**
     * Spend for the current tenant (RLS-scoped) over the given window. Accepts a
     * legacy day count as well as a period.
     *
     * @return array<string, mixed>
     */
    public function forCurrentOrg(SpendPeriod|int $period = 30): array
    {
        $period = SpendPeriod::resolve($period);

        $rows = AiUsageEvent::query()
            ->where('created_at', '>=', $period->since)
            ->selectRaw($period->bucketColumn().', source, module, model, cost, input_tokens, output_tokens, app_id, subject_type, subject_id')
            ->get();

        // Only the org-facing report names artifacts: resolving a name goes
        // through the tenant models, which are RLS-scoped to the caller — so the
        // cross-org reads below would come back blank anyway.
        return $this->shape($rows, $period, withArtifacts: true);
    }

    /**
     * Platform-wide spend across every org for the sysadmin view, plus a
     * per-organization breakdown. The two sources are read separately and merged
     * so each meter is authoritative:
     *   - `system` spend (what the platform pays) from platform.system_ai_usage_events,
     *     which includes calls with no tenant attribution;
     *   - `own` (BYOK) spend from tenant.ai_usage_events via the owner connection
     *     (RLS bypassed) so every org's own-key usage is visible too.
     *
     * @return array<string, mixed>
     */
    public function platformWide(SpendPeriod|int $period = 30): array
    {
        $period = SpendPeriod::resolve($period);
        $bucket = $period->bucketColumn();

        $ownRows = DB::connection('pgsql')->table(Schemas::qualify('ai_usage_events'))
            ->where('source', 'own')
            ->where('created_at', '>=', $period->since)
            ->selectRaw($bucket.", 'own' as source, module, model, organization_id, cost, input_tokens, output_tokens")
            ->get();

        $systemRows = DB::connection('pgsql')->table('platform.system_ai_usage_events')
            ->where('created_at', '>=', $period->since)
            ->selectRaw($bucket.", 'system' as source, module, model, organization_id, cost, input_tokens, output_tokens")
            ->get();

        $rows = $ownRows->concat($systemRows);

        $report = $this->shape($rows, $period);

        // Top organizations by spend (system spend is what the platform pays).
        $byOrg = collect($rows)
            ->groupBy('organization_id')
            ->map(fn ($g, $org) => [
                'organization_id' => $org ?: null,
                'cost' => round((float) $g->sum('cost'), 4),
                'system_cost' => round((float) $g->where('source', 'system')->sum('cost'), 4),
                'calls' => $g->count(),
            ])
            ->sortByDesc('cost')
            ->take(20)
            ->values();

        // Attach the human-readable org name so the dashboard can show it (and
        // link through to the per-org detail) instead of the raw ULID.
        $names = Organization::query()
            ->whereIn('id', $byOrg->pluck('organization_id')->filter()->all())
            ->pluck('name', 'id');

        $report['by_org'] = $byOrg
            ->map(fn ($o) => [
                ...$o,
                'name' => $o['organization_id'] ? ($names[$o['organization_id']] ?? null) : null,
            ])
            ->all();

        return $report;
    }

    /**
     * Spend for a single organization for the sysadmin drill-down. Reads
     * cross-org via the owner connection (RLS bypassed), mirroring platformWide:
     * `own` (BYOK) from tenant.ai_usage_events, `system` from the platform ledger.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(string $organizationId, SpendPeriod|int $period = 30): array
    {
        $period = SpendPeriod::resolve($period);
        $bucket = $period->bucketColumn();

        $ownRows = DB::connection('pgsql')->table(Schemas::qualify('ai_usage_events'))
            ->where('organization_id', $organizationId)
            ->where('source', 'own')
            ->where('created_at', '>=', $period->since)
            ->selectRaw($bucket.", 'own' as source, module, model, cost, input_tokens, output_tokens")
            ->get();

        $systemRows = DB::connection('pgsql')->table('platform.system_ai_usage_events')
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $period->since)
            ->selectRaw($bucket.", 'system' as source, module, model, cost, input_tokens, output_tokens")
            ->get();

        return $this->shape($ownRows->concat($systemRows), $period);
    }

    /**
     * Spend for a single BUILD — every AI call tagged with this app_id (and
     * optionally one conversation), across the builder and the Express dashboard
     * pipeline. RLS-scoped: reads the tenant meter, which carries both own- and
     * system-paid calls that had tenant context, so the total is the real cost
     * of the build regardless of who paid. Shaped for attribution, not the
     * dashboard: totals + per-model + per-conversation, no daily series.
     *
     * The resolved window is reported, not just its length. This report and the
     * org-wide one read the SAME rows but default to DIFFERENT windows (90 days
     * here, 30 there), so their totals for one app diverge whenever it has spend
     * outside the shorter one — which reads as two billing surfaces disagreeing
     * unless each states the dates it actually covers. Observed: a build read as
     * $0.0143 next to $0.1661 for the same app, the gap being one turn from the
     * day before.
     *
     * @return array<string, mixed>
     */
    public function forApp(string $appId, ?string $conversationId = null, int $days = 90): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $query = AiUsageEvent::query()
            ->where('app_id', $appId)
            ->where('created_at', '>=', $since);
        if ($conversationId !== null) {
            $query->where('conversation_id', $conversationId);
        }

        $rows = $query
            ->selectRaw('module, model, source, conversation_id, cost, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, reasoning_tokens')
            ->get();

        $sum = fn (Collection $g): array => [
            'cost' => round((float) $g->sum('cost'), 4),
            'calls' => $g->count(),
            'input_tokens' => (int) $g->sum('input_tokens'),
            'output_tokens' => (int) $g->sum('output_tokens'),
            'cache_read_tokens' => (int) $g->sum('cache_read_tokens'),
            'reasoning_tokens' => (int) $g->sum('reasoning_tokens'),
        ];

        return [
            'app_id' => $appId,
            'conversation_id' => $conversationId,
            'range_days' => $days,
            'window' => [
                'since' => $since->toDateTimeString(),
                'from' => $since->toDateString(),
                'to' => Carbon::today()->toDateString(),
                'timezone' => $since->format('T'),
            ],
            'totals' => $sum($rows),
            'by_model' => $rows->groupBy('model')
                ->map(fn (Collection $g, string $model): array => ['model' => $model] + $sum($g))
                ->sortByDesc('cost')->values()->all(),
            'by_conversation' => $rows->groupBy(fn (object $r): string => (string) ($r->conversation_id ?? ''))
                ->map(fn (Collection $g, string $cid): array => ['conversation_id' => $cid !== '' ? $cid : null] + $sum($g))
                ->sortByDesc('cost')->values()->all(),
            'by_service' => $rows->groupBy(fn (object $r): string => $this->serviceFor($r->module))
                ->map(fn (Collection $g, string $svc): array => ['service' => $svc] + $sum($g))
                ->sortByDesc('cost')->values()->all(),
        ];
    }

    /**
     * Shared shaping of a flat row set into totals + breakdowns + a cost series.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function shape($rows, SpendPeriod $period, bool $withArtifacts = false): array
    {
        $rows = collect($rows);

        $bySource = fn (string $source) => round((float) $rows->where('source', $source)->sum('cost'), 4);

        // Cost series split by source, zero-filled across the window so the chart
        // has a point per bucket (a day, or an hour for a single-day period).
        $byBucket = $rows->groupBy('d');
        $labels = $period->bucketLabels();
        $ownSeries = [];
        $systemSeries = [];
        foreach ($labels as $label) {
            $bucketRows = collect($byBucket->get($label) ?? []);
            $ownSeries[] = round((float) $bucketRows->where('source', 'own')->sum('cost'), 4);
            $systemSeries[] = round((float) $bucketRows->where('source', 'system')->sum('cost'), 4);
        }

        // A model with usage but no catalog price silently meters at $0 —
        // corrupting totals and bypassing budgets. Flag it instead of letting
        // a zero-cost line read as "free".
        $pricing = app(AiPricing::class);
        $modelBreakdown = fn (Collection $g) => collect($g)->groupBy('model')
            ->map(fn ($mg, $model) => array_filter([
                'model' => $model,
                'cost' => round((float) $mg->sum('cost'), 4),
                'calls' => $mg->count(),
                'input_tokens' => (int) $mg->sum('input_tokens'),
                'output_tokens' => (int) $mg->sum('output_tokens'),
                'unpriced' => $pricing->pricesFor((string) $model) === null ? true : null,
            ], fn ($v) => $v !== null))
            ->sortByDesc('cost')
            ->values()
            ->all();

        $byModel = collect($modelBreakdown($rows))->take(15)->all();

        $serviceBreakdown = fn (Collection $g) => collect($g)
            ->groupBy(fn ($r) => $this->serviceFor($r->module ?? null))
            ->map(fn (Collection $sg, string $service) => [
                'service' => $service,
                'cost' => round((float) $sg->sum('cost'), 4),
                'calls' => $sg->count(),
                'input_tokens' => (int) $sg->sum('input_tokens'),
                'output_tokens' => (int) $sg->sum('output_tokens'),
            ])
            ->sortByDesc('cost')
            ->values()
            ->all();

        // Spend grouped by service (Chat, Apps, …), each with its own per-model
        // breakdown so the dashboard can show "Chat $X: model A $z, model B $y".
        $byService = $rows->groupBy(fn ($r) => $this->serviceFor($r->module ?? null))
            ->map(fn ($g, $service) => [
                'service' => $service,
                'cost' => round((float) $g->sum('cost'), 4),
                'calls' => $g->count(),
                'input_tokens' => (int) $g->sum('input_tokens'),
                'output_tokens' => (int) $g->sum('output_tokens'),
                'models' => $modelBreakdown($g),
            ])
            ->sortByDesc('cost')
            ->values()
            ->all();

        // The same rows read the other way round: what the money was spent ON,
        // each artifact broken down by the services it used. Artifact first,
        // because "what did this app cost me" is the question — nesting it the
        // other way splits one app's cost across several service cards.
        $names = $withArtifacts ? SpendArtifact::resolve(
            // Duplicates are fine — resolve() dedupes before it queries.
            $rows->map(fn ($r) => $this->artifactKeyFor($r))->filter()->all(),
        ) : [];

        $byArtifact = ! $withArtifacts ? null : $rows
            ->groupBy(fn ($r) => ($k = $this->artifactKeyFor($r)) === null ? '' : $k[0].':'.$k[1])
            ->map(function (Collection $g, string $key) use ($names, $serviceBreakdown) {
                [$type, $id] = $key === '' ? [null, null] : explode(':', $key, 2);

                return [
                    // An id with no row behind it still gets a line: spend that
                    // outlived its artifact is spend, and dropping it would make
                    // the totals stop adding up.
                    'name' => $names[$key]['name'] ?? null,
                    'kind' => $names[$key]['kind'] ?? ($type === null ? null : SpendArtifact::kindFor($type)),
                    'type' => $type,
                    'id' => $id,
                    'cost' => round((float) $g->sum('cost'), 4),
                    'calls' => $g->count(),
                    'input_tokens' => (int) $g->sum('input_tokens'),
                    'output_tokens' => (int) $g->sum('output_tokens'),
                    'services' => $serviceBreakdown($g),
                ];
            })
            ->sortByDesc('cost')
            ->values()
            ->all();

        return array_filter([
            'range_days' => $period->days(),
            'period' => $period->toArray(),
            'totals' => [
                'cost' => round((float) $rows->sum('cost'), 4),
                'calls' => $rows->count(),
                'input_tokens' => (int) $rows->sum('input_tokens'),
                'output_tokens' => (int) $rows->sum('output_tokens'),
            ],
            'by_source' => [
                'own' => $bySource('own'),
                'system' => $bySource('system'),
            ],
            'by_model' => $byModel,
            'by_service' => $byService,
            'by_artifact' => $byArtifact,
            'series' => [
                'labels' => $labels,
                'own' => $ownSeries,
                'system' => $systemSeries,
            ],
            // Only `by_artifact` is optional; every other key is always present.
        ], fn ($v) => $v !== null);
    }

    /**
     * The artifact a row is attributed to, as a (type, id) pair.
     *
     * App-shaped spend says so through `app_id`, everything else through the
     * polymorphic subject — see {@see App\Services\Ai\AiUsageRecorder}. Reading
     * app_id here is also what lets rows written before the subject column
     * existed still name their build.
     *
     * @return array{0: string, 1: string}|null
     */
    private function artifactKeyFor(object $row): ?array
    {
        $type = $row->subject_type ?? null;
        $id = $row->subject_id ?? null;

        if (($type === null || $id === null) && ($row->app_id ?? null) !== null) {
            return ['app', (string) $row->app_id];
        }

        return ($type === null || $id === null) ? null : [(string) $type, (string) $id];
    }

    /**
     * Map a recorded `module` to the user-facing service bucket shown on the
     * spend dashboard. Related modules (app builder, runtime agents, workflows)
     * roll up into a single "Apps" line.
     *
     * Every module in use needs a case here: the fallback exists for a module
     * shipped after this map, and it renders the raw internal slug — which is
     * how `express`, `chatbot` and `whatsapp` came to show up in the UI as
     * "Express", "Chatbot" and "Whatsapp".
     */
    private function serviceFor(?string $module): string
    {
        return match ($module) {
            'chat' => 'Chat',
            'builder', 'runtime_agent', 'workflow', 'express', 'scaffold' => 'Apps',
            'landing_director' => 'Landing Director',
            'agent' => 'Agents',
            'chatbot' => 'Chatbots',
            'whatsapp' => 'WhatsApp',
            'debate' => 'Debate',
            'embeddings', 'document_ocr' => 'Knowledge',
            null, '' => 'Other',
            default => ucfirst($module),
        };
    }
}
