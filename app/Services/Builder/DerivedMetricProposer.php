<?php

namespace App\Services\Builder;

use App\Services\Express\SemanticProfile;
use App\Services\Records\FieldPaths;
use Illuminate\Support\Str;

/**
 * A real analyst doesn't stop at the columns the data ships — they CONSTRUCT
 * the metric that answers the question. This proposes derived ratios the board
 * doesn't carry (reopen rate = reopened/total, backlog as % of total) from the
 * raw counts already present, computes the real value, and surfaces it as an
 * insight. Deterministic, over the sampled rows.
 */
class DerivedMetricProposer
{
    /**
     * Numerator/denominator name patterns → the metric they form. Both sides
     * are matched against additive measure NAMES; ratio columns are excluded
     * (a rate over a rate is nonsense).
     *
     * @var list<array{num: string, den: string, es: string, en: string}>
     */
    private const RATIOS = [
        ['num' => '/reopen|reabiert/', 'den' => '/total|closed|cerrad/', 'es' => 'Tasa de reapertura', 'en' => 'Reopen rate'],
        ['num' => '/backlog|pendiente|abiert/', 'den' => '/total/', 'es' => 'Backlog como % del total', 'en' => 'Backlog as % of total'],
        ['num' => '/refund|devoluc|reembols/', 'den' => '/order|pedido|total|sale|venta/', 'es' => 'Tasa de devolución', 'en' => 'Return rate'],
    ];

    public function __construct(private SemanticProfile $semantics) {}

    /**
     * @param  array<string, array{object: array<string, mixed>, rows: list<array<string, mixed>>, facts: array<string, mixed>}>  $byObject
     * @return list<array<string, mixed>> finding candidates (insight-shaped)
     */
    public function analyze(array $byObject, bool $es): array
    {
        $out = [];
        $madeRatios = [];
        foreach ($byObject as $entry) {
            foreach (self::RATIOS as $ratio) {
                if (isset($madeRatios[$ratio['es']])) {
                    continue; // one of each ratio across the whole board
                }
                $finding = $this->tryRatio($entry, $ratio, $es);
                if ($finding !== null) {
                    $out[] = $finding;
                    $madeRatios[$ratio['es']] = true;
                }
            }
            if (count($out) >= 2) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array{object: array<string, mixed>, rows: list<array<string, mixed>>}  $entry
     * @param  array{num: string, den: string, es: string, en: string}  $ratio
     * @return array<string, mixed>|null
     */
    private function tryRatio(array $entry, array $ratio, bool $es): ?array
    {
        $object = $entry['object'];
        $additive = collect($object['fields'] ?? [])
            ->filter(fn ($f): bool => is_array($f)
                && in_array($f['type'] ?? '', ['number', 'currency'], true)
                && $this->semantics->measureTypeOf($f) === SemanticProfile::MEASURE_ADDITIVE);

        $num = $additive->first(fn (array $f): bool => preg_match($ratio['num'], Str::lower((string) ($f['name'] ?? $f['slug']))) === 1);
        $den = $additive->first(fn (array $f): bool => preg_match($ratio['den'], Str::lower((string) ($f['name'] ?? $f['slug']))) === 1
            && ($f['id'] ?? null) !== ($num['id'] ?? null));
        if ($num === null || $den === null) {
            return null;
        }

        $paths = FieldPaths::forObject($object);
        $sumNum = $this->sum($entry['rows'], $paths[$num['id']] ?? ($num['slug'] ?? ''));
        $sumDen = $this->sum($entry['rows'], $paths[$den['id']] ?? ($den['slug'] ?? ''));
        if ($sumDen <= 0 || $sumNum < 0) {
            return null;
        }
        $rate = round($sumNum / $sumDen * 100, 1);
        if ($rate <= 0 || $rate >= 100) {
            return null; // a degenerate ratio says nothing
        }

        $label = $es ? $ratio['es'] : $ratio['en'];
        $denName = Str::lower((string) ($den['name'] ?? $den['slug']));
        $numName = Str::lower((string) ($num['name'] ?? $num['slug']));
        $body = $es
            ? "{$label}: {$rate}% — ".number_format($sumNum)." {$numName} de ".number_format($sumDen)." {$denName}. Es una medida que el tablero no trae y que resume la salud en un número."
            : "{$label}: {$rate}% — ".number_format($sumNum)." {$numName} of ".number_format($sumDen)." {$denName}. A metric the board doesn't carry that summarises health in one number.";

        return [
            'kind' => 'derived',
            'kicker' => $es ? 'Métrica derivada' : 'Derived metric',
            'title' => $label,
            'why' => $body,
            'insight' => [
                'type' => 'insight',
                'title' => $label.": {$rate}%",
                'body' => $body,
                'variant' => 'conclusion',
            ],
            'preview' => ['kind' => 'gauge', 'value' => $rate, 'target' => 100],
            'base' => 76,
            'flag' => null,
            'metric' => $ratio['es'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $path): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $v = data_get($row, $path);
            if (is_numeric($v)) {
                $total += (float) $v;
            }
        }

        return $total;
    }
}
