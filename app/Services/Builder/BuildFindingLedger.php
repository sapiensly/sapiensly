<?php

namespace App\Services\Builder;

use App\Models\BuildFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes and reads the build-failure ledger.
 *
 * The three signals it collects are already produced today — the validator's
 * rejections, its design warnings, and the closing critic's verdict — and are
 * currently used once and discarded. Storing them turns "the builder keeps
 * getting page indexes wrong" from an anecdote somebody happened to notice into
 * a query, which is the only way a rail ever gets written for the RIGHT failure.
 *
 * Deliberately dumb: no AI, no embeddings, no retrieval. Nothing here is ever
 * fed back into a prompt. The loop it closes ends in a human writing a
 * deterministic rule or a line of reference doc.
 */
class BuildFindingLedger
{
    /**
     * A single rejected patch can carry a hundred validation errors, all of
     * them the same mistake. Past the first handful they add rows without
     * adding information.
     */
    private const MAX_PER_CALL = 25;

    private const DETAIL_LIMIT = 1000;

    /**
     * Record a batch of findings against one app.
     *
     * Best-effort by design and never throws: a build must not fail because
     * its telemetry could not be written. Returns how many rows landed.
     *
     * @param  list<array{code?: string|null, path?: string|null, at?: string|null, detail?: string|null}>  $findings
     */
    public function record(
        string $appId,
        string $signal,
        array $findings,
        ?string $conversationId = null,
        ?string $model = null,
    ): int {
        $written = 0;

        foreach (array_slice($findings, 0, self::MAX_PER_CALL) as $finding) {
            $detail = trim((string) ($finding['detail'] ?? ''));
            if ($detail === '') {
                continue;
            }

            try {
                BuildFinding::create([
                    'app_id' => $appId,
                    'conversation_id' => $conversationId,
                    'model' => $model,
                    'signal' => $signal,
                    'code' => $this->clip($finding['code'] ?? null, 64),
                    'path' => $this->clip($finding['path'] ?? null, 255),
                    'at' => $finding['at'] ?? null,
                    'detail' => mb_substr($detail, 0, self::DETAIL_LIMIT),
                ]);
                $written++;
            } catch (\Throwable) {
                // No tenant scope, a dropped connection, a column that grew a
                // constraint — none of it is worth failing a build over.
                return $written;
            }
        }

        return $written;
    }

    /**
     * What has been going wrong, over a window.
     *
     * Scoped by RLS to the caller's own builds; `$appId` narrows further to one
     * app. The shape answers the three questions the ledger was built for: what
     * fails most often (`top_codes`), which model it fails on (`by_model`), and
     * what the failures actually said (`recent`).
     *
     * @return array<string, mixed>
     */
    public function report(?string $appId = null, int $days = 30, int $recentLimit = 20): array
    {
        $since = Carbon::today()->subDays(max(1, $days) - 1);

        $base = fn () => BuildFinding::query()
            ->where('created_at', '>=', $since)
            ->when($appId !== null, fn ($q) => $q->where('app_id', $appId));

        $bySignal = $base()
            ->select('signal', DB::raw('count(*) as total'))
            ->groupBy('signal')
            ->pluck('total', 'signal')
            ->map(fn ($n) => (int) $n)
            ->all();

        $topCodes = $base()
            ->select('signal', 'code', DB::raw('count(*) as total'))
            ->groupBy('signal', 'code')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($row): array => [
                'signal' => $row->signal,
                'code' => $row->code,
                'count' => (int) $row->total,
            ])
            ->all();

        // Findings alone rank a model by how much it was USED. Divided by the
        // builds it produced, the number becomes comparable — the whole point
        // of asking "would a stronger model do better?" with evidence.
        $byModel = $base()
            ->select('model', DB::raw('count(*) as total'), DB::raw('count(distinct conversation_id) as builds'))
            ->groupBy('model')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row): array {
                $builds = (int) $row->builds;

                return [
                    'model' => $row->model,
                    'findings' => (int) $row->total,
                    'builds' => $builds,
                    'per_build' => $builds > 0 ? round((int) $row->total / $builds, 1) : null,
                ];
            })
            ->all();

        $recent = $base()
            ->latest()
            ->limit(max(0, $recentLimit))
            ->get()
            ->map(fn (BuildFinding $f): array => array_filter([
                'at_time' => $f->created_at?->toIso8601String(),
                'app_id' => $f->app_id,
                'conversation_id' => $f->conversation_id,
                'model' => $f->model,
                'signal' => $f->signal,
                'code' => $f->code,
                'path' => $f->path,
                'where' => $f->at,
                'detail' => $f->detail,
            ], fn ($v) => $v !== null && $v !== ''))
            ->all();

        return [
            'window' => [
                'days' => $days,
                'since' => $since->toDateString(),
                'timezone' => config('app.timezone'),
            ],
            'totals' => [
                'findings' => array_sum($bySignal),
                'by_signal' => $bySignal,
            ],
            'top_codes' => $topCodes,
            'by_model' => $byModel,
            'recent' => $recent,
        ];
    }

    private function clip(?string $value, int $length): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
