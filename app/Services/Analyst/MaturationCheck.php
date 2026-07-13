<?php

namespace App\Services\Analyst;

use App\Services\Records\FieldPaths;

/**
 * The most recent rows are not bad news. They are not news yet.
 *
 * A live source reports today's orders the moment they are placed, but an order
 * cannot be *delivered on time* until its promised date arrives. So the last few
 * days of a delivery series always read as catastrophe: 0 delivered out of 67,
 * every day, while volume holds. A board built on that tells the director the
 * operation collapsed to zero — and sends him to shout at a courier who did
 * nothing wrong. The real rate over the window that has actually resolved is 86%.
 *
 * Nothing caught this. Every guard we own polices HOW a number is computed:
 * is the rate averaged, does the figure come from the data, does the chart have
 * an axis. None asks whether the data is RIPE. DataQualityCheck even asks the
 * opposite question — "is this source stale?" — and here the source was perfectly
 * fresh. The rows were too YOUNG to be true.
 *
 * The signature is arithmetic, not intuition, and it needs no domain knowledge:
 *
 *   RESOLVED SHARE = numerator / denominator — the fraction of each period's
 *   inputs that have reached ANY outcome. RatioIdentity already proved which
 *   column is the input and which the outcome, so this is free.
 *
 * A genuine operational collapse sinks the on-time count and RAISES the late
 * count: the orders resolve, badly. An immature period sinks BOTH — nothing has
 * resolved at all. That is the discriminator, and when the identity carries a
 * `minus` column (the late count) it turns suspicion into proof: zero delivered
 * AND zero late, on normal volume, is not a bad day. It is a day that has not
 * happened yet.
 */
class MaturationCheck
{
    /** Below this share of the baseline, a period has plainly not resolved. */
    private const IMMATURE_FACTOR = 0.8;

    /** A series whose periods do not normally resolve tells us nothing. */
    private const MIN_BASELINE = 0.7;

    /** One low period at the edge is noise; a run of them is a lag. */
    private const MIN_RUN = 2;

    /** Enough history behind the tail to trust the baseline. */
    private const MIN_MATURE_ROWS = 5;

    /** The denominator must be NORMAL in the tail — otherwise it is just a quiet day. */
    private const VOLUME_FLOOR = 0.5;

    /**
     * Lateness must have COLLAPSED, not merely dipped, for the tail to be immature.
     * A real catastrophe drives this the other way — orders resolve, and resolve
     * late — so the direction of this one number is what separates "has not happened
     * yet" from "happened, and it was terrible".
     */
    private const LATE_COLLAPSE_FACTOR = 0.5;

    /**
     * The trailing periods that have not resolved yet, per derived rate.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows  chronologically ordered
     * @param  list<array<string, mixed>>  $identities  from RatioIdentity::detect
     * @return list<array<string, mixed>>
     */
    public function detect(array $object, array $rows, array $identities): array
    {
        $paths = FieldPaths::forObject($object);
        $out = [];

        foreach ($identities as $identity) {
            $found = $this->immatureTail($rows, $paths, $identity);
            if ($found !== null) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /**
     * Rows with the PROVEN immature tail removed — what the analyst should read.
     *
     * Only a conclusive tail is deleted. Dropping data is a strong act: do it on a
     * suspicion and the guard will one day quietly erase the very catastrophe it
     * exists to surface. When the source cannot distinguish "not resolved yet" from
     * "resolved badly", the rows stay and the warning does the talking.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $maturations
     * @return list<array<string, mixed>>
     */
    public function matureRows(array $rows, array $maturations): array
    {
        // The longest tail any rate PROVES immature. If delivery has not resolved
        // for five days, the whole period is unreadable, not just its OTD column.
        $drop = 0;
        foreach ($maturations as $m) {
            if (($m['conclusive'] ?? false) === true) {
                $drop = max($drop, (int) $m['immature_periods']);
            }
        }

        return $drop > 0 ? array_slice($rows, 0, count($rows) - $drop) : $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $paths
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    private function immatureTail(array $rows, array $paths, array $identity): ?array
    {
        $numPath = $paths[$identity['numerator']['id']] ?? null;
        $denPath = $paths[$identity['denominator']['id']] ?? null;
        $minusPath = isset($identity['minus']['id']) ? ($paths[$identity['minus']['id']] ?? null) : null;
        if ($numPath === null || $denPath === null) {
            return null;
        }

        // The share of each period's inputs that reached ANY outcome.
        $resolved = [];
        $volumes = [];
        foreach ($rows as $i => $row) {
            $n = data_get($row, $numPath);
            $d = data_get($row, $denPath);
            if (! is_numeric($n) || ! is_numeric($d) || (float) $d <= 0) {
                $resolved[$i] = null;

                continue;
            }
            $resolved[$i] = (float) $n / (float) $d;
            $volumes[$i] = (float) $d;
        }

        $baseline = $this->median(array_values(array_filter($resolved, fn ($v) => $v !== null)));
        $normalVolume = $this->median(array_values($volumes));
        if ($baseline === null || $baseline < self::MIN_BASELINE || $normalVolume === null || $normalVolume <= 0) {
            return null;
        }

        // Walk back from the present while periods plainly have not resolved AND
        // the volume is normal. A quiet day is not an immature one.
        $floor = $baseline * self::IMMATURE_FACTOR;
        $run = 0;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $share = $resolved[$i] ?? null;
            $volume = $volumes[$i] ?? null;
            if ($share === null || $volume === null || $share >= $floor || $volume < $normalVolume * self::VOLUME_FLOOR) {
                break;
            }
            $run++;
        }

        if ($run < self::MIN_RUN || count($rows) - $run < self::MIN_MATURE_ROWS) {
            return null;
        }

        $tail = array_slice($rows, -$run);
        $mature = array_slice($rows, 0, count($rows) - $run);

        // THE DISCRIMINATOR. A collapsing outcome share is not enough — a genuine
        // catastrophe sinks it too. The difference is what happened to the orders:
        // a real collapse RESOLVES them badly, so the late rate climbs; an immature
        // period resolves nothing, so the late rate falls toward zero along with
        // everything else. Get this backwards and the guard deletes the catastrophe
        // it was built to protect — which is exactly what the first draft did, and
        // what its test caught.
        $conclusive = false;
        if ($minusPath !== null) {
            $baselineLate = $this->rateOf($mature, $minusPath, $denPath);
            $tailLate = $this->rateOf($tail, $minusPath, $denPath);
            if ($baselineLate === null || $tailLate === null) {
                return null;
            }
            $conclusive = $tailLate < $baselineLate * self::LATE_COLLAPSE_FACTOR;

            // The lateness held or grew: these orders resolved, and resolved badly.
            // That is news, not noise. Report nothing and let the analyst see it.
            if (! $conclusive) {
                return null;
            }
        }

        return [
            'rate' => $identity['rate'],
            'numerator' => $identity['numerator'],
            'denominator' => $identity['denominator'],
            'immature_periods' => $run,
            // What the board shows today, dragged down by periods that have not happened.
            'full_window_rate' => $this->weightedRate($rows, $paths, $identity),
            // What the business actually did, over the window that has resolved.
            'mature_rate' => $this->weightedRate($mature, $paths, $identity),
            'baseline_resolved_pct' => round($baseline * 100, 1),
            'tail_resolved_pct' => round(($this->median(array_map(
                fn (int $i): float => $resolved[$i] ?? 0.0,
                range(count($rows) - $run, count($rows) - 1),
            )) ?? 0) * 100, 1),
            // Proven when the source carries a late column (the lateness vanished
            // instead of spiking). Without one, nothing distinguishes "not shipped
            // yet" from "never shipped", so we say so and DO NOT delete the rows —
            // see matureRows.
            'conclusive' => $conclusive,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $paths
     * @param  array<string, mixed>  $identity
     */
    private function weightedRate(array $rows, array $paths, array $identity): float
    {
        $numPath = $paths[$identity['numerator']['id']];
        $denPath = $paths[$identity['denominator']['id']];
        $minusPath = isset($identity['minus']['id']) ? ($paths[$identity['minus']['id']] ?? null) : null;

        $num = 0.0;
        $den = 0.0;
        foreach ($rows as $row) {
            $n = data_get($row, $numPath);
            $d = data_get($row, $denPath);
            $m = $minusPath !== null ? data_get($row, $minusPath) : 0;
            if (! is_numeric($n) || ! is_numeric($d) || ! is_numeric($m)) {
                continue;
            }
            $num += (float) $n - (float) $m;
            $den += (float) $d;
        }

        return $den > 0 ? round($num / $den * 100, 1) : 0.0;
    }

    /**
     * The share of the denominator that one column accounts for, over these rows.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function rateOf(array $rows, string $path, string $denPath): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($rows as $row) {
            $value = data_get($row, $path);
            $d = data_get($row, $denPath);
            if (! is_numeric($value) || ! is_numeric($d)) {
                continue;
            }
            $num += (float) $value;
            $den += (float) $d;
        }

        return $den > 0 ? $num / $den : null;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
