<?php

namespace App\Services\Builder;

use Illuminate\Support\Str;

/**
 * Pure transformations over a conversation's `build_plan` (the cross-turn build
 * tracker). The invariant that shapes all of this: a builder turn applies its
 * accumulated proposal as exactly ONE app version, so within a turn many steps
 * close against one version, and across the build each step closes against
 * exactly one version. Progress is therefore derived from a real applied version
 * — never from the model's prose (see closeApplied / resetInProgress).
 *
 * Plan shape:
 *   { schema: 1, goal: ?string, status: active|done|abandoned, steps: [ {
 *       id, title, detail: ?string, status: pending|in_progress|done|skipped|failed,
 *       applied_version_id: ?string, version_number: ?int, closed_by_summary: ?string,
 *       error: ?string
 *   } ] }
 */
class BuildPlan
{
    public const SCHEMA = 1;

    /** Statuses a step that's being targeted may legally move out of. */
    private const TARGETABLE = ['pending', 'failed'];

    /**
     * Create or edit a plan, reconciling by step id: existing ids keep their
     * status + version stamps (only title/detail update), ids the model omits
     * are minted fresh as pending, and prior steps absent from the new list are
     * preserved — kept as 'done' if they had closed, otherwise marked 'skipped'.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  list<array<string, mixed>>  $stepsInput
     * @return array<string, mixed>
     */
    public static function reconcile(?array $existing, ?string $goal, array $stepsInput): array
    {
        $byId = [];
        foreach ($existing['steps'] ?? [] as $step) {
            if (isset($step['id'])) {
                $byId[$step['id']] = $step;
            }
        }

        $steps = [];
        $seen = [];
        foreach ($stepsInput as $input) {
            $title = trim((string) ($input['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $detail = isset($input['detail']) && trim((string) $input['detail']) !== ''
                ? trim((string) $input['detail'])
                : null;

            $id = $input['id'] ?? null;
            if ($id !== null && isset($byId[$id])) {
                // Edit in place: keep status + version stamps, refresh text.
                $step = $byId[$id];
                $step['title'] = $title;
                $step['detail'] = $detail;
                $steps[] = $step;
                $seen[$id] = true;
            } else {
                $steps[] = self::newStep($title, $detail);
            }
        }

        // Preserve prior steps the model dropped from the list.
        foreach ($existing['steps'] ?? [] as $step) {
            $id = $step['id'] ?? null;
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            if (($step['status'] ?? 'pending') !== 'done') {
                $step['status'] = 'skipped';
            }
            $steps[] = $step;
        }

        return self::withDerivedStatus([
            'schema' => self::SCHEMA,
            'goal' => $goal !== null && trim($goal) !== '' ? trim($goal) : ($existing['goal'] ?? null),
            'status' => $existing['status'] ?? 'active',
            'steps' => $steps,
        ]);
    }

    /**
     * Mark the given steps in_progress (the turn's targets). Only pending/failed
     * steps move; unknown ids and already-done steps are ignored.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $stepIds
     * @return array<string, mixed>
     */
    public static function markInProgress(array $plan, array $stepIds): array
    {
        $wanted = array_flip($stepIds);
        $plan['steps'] = array_map(function (array $step) use ($wanted): array {
            if (isset($wanted[$step['id'] ?? '']) && in_array($step['status'] ?? 'pending', self::TARGETABLE, true)) {
                $step['status'] = 'in_progress';
                $step['error'] = null;
            }

            return $step;
        }, $plan['steps'] ?? []);

        return self::withDerivedStatus($plan);
    }

    /**
     * Close every in_progress step against the version that just applied. This
     * is the deterministic progress gate: a step only becomes 'done' because a
     * real version landed, not because the model said so. When NO step was
     * targeted (the model applied real work but skipped target_plan_steps —
     * observed with slow models on auto-resumed turns), the version closes the
     * FIRST open step instead: an applied version while a plan is active is
     * plan progress, and leaving it unattributed strands the plan (the next
     * autonomous turn then re-does a finished step, proposes nothing and
     * pauses).
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function closeApplied(array $plan, string $versionId, ?int $versionNumber, ?string $summary): array
    {
        $close = function (array $step) use ($versionId, $versionNumber, $summary): array {
            $step['status'] = 'done';
            $step['applied_version_id'] = $versionId;
            $step['version_number'] = $versionNumber;
            $step['closed_by_summary'] = $summary;
            $step['error'] = null;

            return $step;
        };

        $closedAny = false;
        $steps = $plan['steps'] ?? [];
        foreach ($steps as $i => $step) {
            if (($step['status'] ?? null) === 'in_progress') {
                $steps[$i] = $close($step);
                $closedAny = true;
            }
        }

        if (! $closedAny) {
            foreach ($steps as $i => $step) {
                if (in_array($step['status'] ?? 'pending', self::TARGETABLE, true)) {
                    $steps[$i] = $close($step);
                    break;
                }
            }
        }

        $plan['steps'] = $steps;

        return self::withDerivedStatus($plan);
    }

    /**
     * Mark in_progress steps as failed (the turn's apply errored) so they stay
     * visible for retry.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function failInProgress(array $plan, ?string $error): array
    {
        $plan['steps'] = array_map(function (array $step) use ($error): array {
            if (($step['status'] ?? null) === 'in_progress') {
                $step['status'] = 'failed';
                $step['error'] = $error;
            }

            return $step;
        }, $plan['steps'] ?? []);

        return self::withDerivedStatus($plan);
    }

    /**
     * Send in_progress steps back to pending — the turn produced no applied
     * version (pure chat, or a phantom turn that described an edit but never
     * proposed). The plan correctly shows no advance.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function resetInProgress(array $plan): array
    {
        $plan['steps'] = array_map(function (array $step): array {
            if (($step['status'] ?? null) === 'in_progress') {
                $step['status'] = 'pending';
            }

            return $step;
        }, $plan['steps'] ?? []);

        return self::withDerivedStatus($plan);
    }

    /**
     * Reopen steps that were closed by a version being reverted, so the plan
     * never claims 'done' over work no longer live in the manifest.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function reopenForVersion(array $plan, string $versionId): array
    {
        $plan['steps'] = array_map(function (array $step) use ($versionId): array {
            if (($step['status'] ?? null) === 'done' && ($step['applied_version_id'] ?? null) === $versionId) {
                $step['status'] = 'pending';
                $step['applied_version_id'] = null;
                $step['version_number'] = null;
                $step['closed_by_summary'] = null;
            }

            return $step;
        }, $plan['steps'] ?? []);

        return self::withDerivedStatus($plan);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    public static function inProgressIds(array $plan): array
    {
        return array_values(array_map(
            fn (array $s): string => $s['id'],
            array_filter($plan['steps'] ?? [], fn (array $s): bool => ($s['status'] ?? null) === 'in_progress'),
        ));
    }

    /**
     * A compact view for tool returns / per-turn context injection.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function compact(array $plan): array
    {
        return [
            'goal' => $plan['goal'] ?? null,
            'status' => $plan['status'] ?? 'active',
            'steps' => array_map(fn (array $s): array => array_filter([
                'id' => $s['id'] ?? null,
                'title' => $s['title'] ?? null,
                'status' => $s['status'] ?? 'pending',
                'version_number' => $s['version_number'] ?? null,
            ], fn ($v) => $v !== null), $plan['steps'] ?? []),
        ];
    }

    /**
     * One-line-per-step plain-text rendering for injecting the plan into a turn.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function toContextLines(array $plan): string
    {
        $lines = [];
        foreach ($plan['steps'] ?? [] as $i => $step) {
            $lines[] = ($i + 1).'. ['.($step['status'] ?? 'pending').'] '.($step['title'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function withDerivedStatus(array $plan): array
    {
        // 'abandoned' is an explicit user action — never auto-recompute it.
        if (($plan['status'] ?? null) === 'abandoned') {
            return $plan;
        }

        $steps = $plan['steps'] ?? [];
        $open = array_filter($steps, fn (array $s): bool => ! in_array($s['status'] ?? 'pending', ['done', 'skipped'], true));
        $plan['status'] = ($steps !== [] && $open === []) ? 'done' : 'active';

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private static function newStep(string $title, ?string $detail): array
    {
        return [
            'id' => 'stp_'.strtolower((string) Str::ulid()),
            'title' => $title,
            'detail' => $detail,
            'status' => 'pending',
            'applied_version_id' => null,
            'version_number' => null,
            'closed_by_summary' => null,
            'error' => null,
        ];
    }
}
