<?php

namespace App\Services\Analyst;

use App\Services\Express\SemanticProfile;
use App\Services\Records\FieldPaths;

/**
 * A rate column that the data PROVES is a ratio of two other columns.
 *
 * `otd_pct` is not an opinion: on every row it equals pedidos_entregados ÷
 * total_pedidos. When that identity holds, averaging the rate column is not "an
 * approximation" — it is a different number, and a wrong one. The mean of daily
 * percentages weights a day with 3 orders exactly like a day with 500, so one
 * late order on a quiet day reads as a 0% catastrophe. The honest rate is
 * SUM(numerator) ÷ SUM(denominator), which is what `ratio_denominator` computes.
 *
 * The identity is DISCOVERED, not guessed. Names are a hint and nothing more —
 * a column called `conversion_pct` might be anything — so this checks the
 * arithmetic row by row and only claims the relationship when the data carries
 * it. That is the difference between knowing and assuming, and it is the whole
 * reason this can be trusted to rewrite a KPI.
 */
class RatioIdentity
{
    /**
     * Rows needed before the identity may hold on merely MOST of them.
     *
     * Above this, a stray row is noise (a late correction, a backfill) and 90% is
     * proof. Below it, there is no room for noise and none is granted — see
     * SMALL_SAMPLE_ROWS.
     */
    private const MIN_ROWS = 8;

    /** The share of rows the identity must hold on, given a real sample. */
    private const MIN_MATCH = 0.9;

    /**
     * The absolute floor — and the reason it exists.
     *
     * MIN_ROWS used to be the floor too, and a source that returned FIVE buckets got
     * no protection at all: no identity, no maturation, no stamp, and therefore
     * nothing for the validator to refuse. The board shipped avg(otd_pct_global) —
     * even though the rate was a_tiempo/entregados_total, EXACT on all five rows, and
     * a correct ratio KPI was there for the taking. Worse, the guard said nothing
     * while it abstained.
     *
     * A two-decimal ratio that lands exactly on five rows carrying different values
     * is not a coincidence. So a small sample is admissible — at the price of
     * perfection: every row must match, and the rate must actually VARY (a column
     * that is 100 on every row can be "explained" by any pair where num == den).
     */
    private const SMALL_SAMPLE_ROWS = 4;

    /** How far a row may sit from the identity and still count (percentage points). */
    private const TOLERANCE_PCT = 0.6;

    public function __construct(private SemanticProfile $semantics) {}

    /**
     * Every rate column whose value the data derives from two others.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{rate: array<string,mixed>, numerator: array<string,mixed>, denominator: array<string,mixed>, matched: int, rows: int, scale: int, true_rate: float, averaged_rate: float}>
     */
    public function detect(array $object, array $rows): array
    {
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $paths = FieldPaths::forObject($object);

        $rates = [];
        $additive = [];
        foreach ($fields as $field) {
            if (! in_array($field['type'] ?? '', ['number', 'currency'], true)) {
                continue;
            }
            $type = $this->semantics->measureTypeIn($object, $rows, $field);
            if ($type === SemanticProfile::MEASURE_RATIO) {
                $rates[] = $field;
            } elseif ($type === SemanticProfile::MEASURE_ADDITIVE) {
                $additive[] = $field;
            }
        }

        if ($rates === [] || count($additive) < 2) {
            return [];
        }

        $out = [];
        foreach ($rates as $rate) {
            $found = $this->identityFor($object, $rows, $paths, $rate, $additive);
            if ($found !== null) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $paths
     * @param  array<string, mixed>  $rate
     * @param  list<array<string, mixed>>  $additive
     * @return array<string, mixed>|null
     */
    private function identityFor(array $object, array $rows, array $paths, array $rate, array $additive): ?array
    {
        $ratePath = $paths[$rate['id']] ?? null;
        if ($ratePath === null) {
            return null;
        }

        // A rate stored 0-1 is a fraction; one stored 0-100 is already points.
        $rateValues = $this->valuesOf($rows, $ratePath);
        if (count($rateValues) < self::SMALL_SAMPLE_ROWS) {
            return null;
        }
        // A rate that never moves can be "explained" by any pair where num == den.
        // With a real sample that is merely uninformative; with five rows it is a
        // false positive waiting to happen.
        if (count($rateValues) < self::MIN_ROWS && count(array_unique($rateValues)) < 2) {
            return null;
        }
        $scale = $this->semantics->percentScale($rate, $rateValues) === 'fraction' ? 100 : 1;

        // The numerator is a column — or the DIFFERENCE of two. A source that
        // reports delivered and late does not also report on-time: the OTD it
        // publishes is (delivered − late) ÷ total, and a detector that only tried
        // simple pairs would have declared the rate underivable and left the board
        // averaging it. Guessing that from the names would have been a coin flip;
        // the arithmetic settles it.
        foreach ($this->numerators($additive) as $num) {
            foreach ($additive as $den) {
                $found = $this->checkIdentity($rows, $paths, $rate, $ratePath, $num, $den, $scale);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Every numerator worth trying: each additive column, and each difference of
     * two of them.
     *
     * @param  list<array<string, mixed>>  $additive
     * @return list<array{field: array<string,mixed>, minus: array<string,mixed>|null}>
     */
    private function numerators(array $additive): array
    {
        $out = [];
        foreach ($additive as $field) {
            $out[] = ['field' => $field, 'minus' => null];
        }
        foreach ($additive as $field) {
            foreach ($additive as $minus) {
                if (($field['id'] ?? null) !== ($minus['id'] ?? null)) {
                    $out[] = ['field' => $field, 'minus' => $minus];
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $paths
     * @param  array<string, mixed>  $rate
     * @param  array{field: array<string,mixed>, minus: array<string,mixed>|null}  $num
     * @param  array<string, mixed>  $den
     * @return array<string, mixed>|null
     */
    private function checkIdentity(array $rows, array $paths, array $rate, string $ratePath, array $num, array $den, int $scale): ?array
    {
        $numPath = $paths[$num['field']['id']] ?? null;
        $minusPath = $num['minus'] !== null ? ($paths[$num['minus']['id']] ?? null) : null;
        $denPath = $paths[$den['id']] ?? null;

        if ($numPath === null || $denPath === null || ($num['minus'] !== null && $minusPath === null)) {
            return null;
        }
        // The numerator must not be the denominator, nor contain it as its whole self.
        if ($num['minus'] === null && $num['field']['id'] === $den['id']) {
            return null;
        }

        $matched = 0;
        $checked = 0;
        $sumNum = 0.0;
        $sumDen = 0.0;
        $sumRate = 0.0;

        foreach ($rows as $row) {
            $r = data_get($row, $ratePath);
            $n = data_get($row, $numPath);
            $d = data_get($row, $denPath);
            $m = $minusPath !== null ? data_get($row, $minusPath) : 0;

            if (! is_numeric($r) || ! is_numeric($n) || ! is_numeric($d) || ! is_numeric($m) || (float) $d <= 0) {
                continue;
            }

            $numerator = (float) $n - (float) $m;
            $checked++;
            $sumNum += $numerator;
            $sumDen += (float) $d;
            $sumRate += (float) $r * $scale;

            $implied = ($numerator / (float) $d) * 100;
            if (abs(((float) $r * $scale) - $implied) <= self::TOLERANCE_PCT) {
                $matched++;
            }
        }

        // A real sample may carry a stray row — a backfill, a late correction — so
        // 90% proves it. A small sample has no room for noise and gets no slack:
        // every row must land, or the identity is not claimed.
        $required = $checked >= self::MIN_ROWS ? self::MIN_MATCH : 1.0;

        if ($checked < self::SMALL_SAMPLE_ROWS || $matched / $checked < $required || $sumDen <= 0) {
            return null;
        }

        return [
            'rate' => $rate,
            'numerator' => $num['field'],
            'minus' => $num['minus'],
            'denominator' => $den,
            'matched' => $matched,
            'rows' => $checked,
            'scale' => $scale,
            // What the rate ACTUALLY is over the window: the volume-weighted truth.
            'true_rate' => round($sumNum / $sumDen * 100, 1),
            // What averaging the rate column says instead — the number a board shows
            // today, in which a day with 3 orders weighs like a day with 500.
            'averaged_rate' => round($sumRate / $checked, 1),
            // A KPI can only express a numerator that IS a column. A difference has
            // no column to point at, so the board cannot compute it — it can only be
            // told the truth.
            'expressible_as_kpi' => $num['minus'] === null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<float>
     */
    private function valuesOf(array $rows, string $path): array
    {
        return array_values(array_map('floatval', array_filter(
            array_map(fn (array $row) => data_get($row, $path), $rows),
            'is_numeric',
        )));
    }
}
