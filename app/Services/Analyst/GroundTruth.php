<?php

namespace App\Services\Analyst;

use App\Services\Records\FieldPaths;

/**
 * Every number this data can honestly support.
 *
 * A model writing an insight card is doing the one thing it is worst at: stating
 * figures from memory. On the Yuhu board it wrote "solo 92 de 265 pedidos
 * entregados a tiempo (50.2%)" — 265 was right, 50.2% was right, and 92 was
 * invented. The sentence contradicts itself (92/265 is 34.7%), and it sat on the
 * page next to a chart, which is what makes it dangerous: everything around it
 * was real.
 *
 * {@see FactGuard::onlyKnownNumbers} already knows how to catch that. What it
 * needs is the ground truth to check against, and the raw cells are not enough:
 * a legitimate insight says "80% of the backlog sits in 4 reasons", and 80
 * appears in no cell. So this derives the LATTICE of numbers the data supports —
 * the cells, the aggregates over each measure, the share each category holds,
 * the volume-weighted rate behind a derived percentage — and any figure outside
 * it is one the model made up.
 *
 * Deliberately generous. The purpose is to catch invented numbers, not to police
 * arithmetic style, and a false rejection teaches people to route around the
 * guard. Anything the platform itself could compute from these rows belongs here.
 */
class GroundTruth
{
    /** Categories worth deriving a share for; beyond this it is an id column, not a dimension. */
    private const MAX_CATEGORIES = 40;

    /**
     * The numbers, as prose FactGuard can check against.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     */
    public function forObject(array $object, array $rows): string
    {
        return implode(' ', $this->numbersFor($object, $rows));
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function numbersFor(array $object, array $rows): array
    {
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $paths = FieldPaths::forObject($object);

        $known = [(string) count($rows)];

        $measures = [];
        $dimensions = [];
        foreach ($fields as $field) {
            $path = $paths[$field['id']] ?? null;
            if ($path === null) {
                continue;
            }
            if (in_array($field['type'] ?? '', ['number', 'currency', 'rating', 'slider'], true)) {
                $measures[$field['id']] = $path;
            } elseif (in_array($field['type'] ?? '', ['string', 'single_select', 'multi_select', 'boolean'], true)) {
                $dimensions[$field['id']] = $path;
            }
        }

        // Every value the rows literally carry, dates included — a date is how an
        // insight names WHEN something happened, and "6 de julio" must survive.
        foreach ($rows as $row) {
            foreach ($paths as $path) {
                $value = data_get($row, $path);
                if (is_scalar($value)) {
                    $known[] = (string) $value;
                }
            }
        }

        foreach ($measures as $fieldId => $path) {
            $values = $this->valuesOf($rows, $path);
            if ($values === []) {
                continue;
            }
            $known = array_merge($known, $this->statsOf($values));

            // The share each category holds of this measure — "80% of the backlog
            // sits in 4 reasons" is a real claim about real data, and no cell holds
            // the 80.
            foreach ($dimensions as $dimPath) {
                $known = array_merge($known, $this->sharesOf($rows, $dimPath, $path));
            }

            // The rate a derived percentage actually is: SUM(numerator)/SUM(denominator),
            // which is the number the analyst reports and the KPI computes. An insight
            // is allowed to state it; it is the AVERAGE of the column that is the lie.
            $derived = collect($fields)->firstWhere('id', $fieldId)['derived_rate'] ?? null;
            if (is_array($derived)) {
                $known = array_merge($known, $this->weightedRate($rows, $paths, $derived));
            }
        }

        return array_values(array_unique(array_filter($known, fn (string $v): bool => $v !== '')));
    }

    /**
     * @param  list<float>  $values
     * @return list<string>
     */
    private function statsOf(array $values): array
    {
        $sum = array_sum($values);
        $count = count($values);
        sort($values);

        return $this->rounded([
            $sum,
            $sum / $count,
            min($values),
            max($values),
            $values[intdiv($count, 2)],
            $count,
        ]);
    }

    /**
     * Each category's share of a measure, and its total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function sharesOf(array $rows, string $dimPath, string $measurePath): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $key = data_get($row, $dimPath);
            $value = data_get($row, $measurePath);
            if (! is_scalar($key) || ! is_numeric($value)) {
                continue;
            }
            $totals[(string) $key] = ($totals[(string) $key] ?? 0) + (float) $value;
        }

        $grand = array_sum($totals);
        if ($totals === [] || count($totals) > self::MAX_CATEGORIES || $grand <= 0) {
            return [];
        }

        $out = [];
        arsort($totals);
        $running = 0.0;
        foreach ($totals as $value) {
            $running += $value;
            // The category's own share, and the cumulative share of the top-N — the
            // two shapes a concentration claim ever takes.
            $out = array_merge($out, $this->rounded([$value, $value / $grand * 100, $running / $grand * 100]));
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $paths
     * @param  array<string, mixed>  $derived
     * @return list<string>
     */
    private function weightedRate(array $rows, array $paths, array $derived): array
    {
        $numPath = $paths[$derived['numerator_field_id'] ?? ''] ?? null;
        $denPath = $paths[$derived['denominator_field_id'] ?? ''] ?? null;
        $minusPath = isset($derived['minus_field_id']) ? ($paths[$derived['minus_field_id']] ?? null) : null;
        if ($numPath === null || $denPath === null) {
            return [];
        }

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

        if ($den <= 0) {
            return [];
        }

        return $this->rounded([$num, $den, $num / $den * 100]);
    }

    /**
     * A figure is quotable at any sane precision: 50, 50.2, 50.25.
     *
     * @param  list<float|int>  $values
     * @return list<string>
     */
    private function rounded(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            foreach ([0, 1, 2] as $precision) {
                $out[] = (string) round((float) $value, $precision);
            }
        }

        return $out;
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
