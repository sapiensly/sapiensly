<?php

namespace App\Services\Builder;

use App\Services\Express\SemanticProfile;
use Illuminate\Support\Str;

/**
 * The insight no single chart can show: JOIN two overlapping sources on their
 * shared dimension and read the relationship between a VOLUME measure in one
 * and a PERFORMANCE ratio in the other — «the highest-volume reasons have the
 * worst FCR». This is where an analyst earns their keep, and it's exactly the
 * kind of board (many reason/cause/FCR breakdowns of the same tickets) where it
 * pays off. Deterministic, over the rows already sampled.
 *
 * A cross-source finding is an INSIGHT (a statement + evidence), not a chart —
 * a chart block reads one object, and the value lives in the join — so it's
 * added to the board as an `insight` block.
 */
class CrossSourceAnalyzer
{
    /** Categories that must join across the two sources before a claim is safe. */
    private const MIN_SHARED = 4;

    public function __construct(private SemanticProfile $semantics) {}

    /**
     * @param  array<string, array{object: array<string, mixed>, rows: list<array<string, mixed>>, facts: array<string, mixed>}>  $byObject
     * @param  array<string, string>  $names  field_id → normalised name
     * @param  array<string, string>  $hints  object_id → distinguishing token
     * @return list<array<string, mixed>> finding candidates
     */
    public function analyze(array $byObject, array $names, array $hints, bool $es): array
    {
        // Bucket sources by the dimension they break down by, tagging each with
        // its leading volume (additive) and performance (ratio) measure.
        $byDimension = [];
        foreach ($byObject as $id => $entry) {
            $shape = $this->shapeOf($entry['object'], $names, $hints[$id] ?? '');
            if ($shape === null) {
                continue;
            }
            $byDimension[$shape['dim']][] = $shape + ['entry' => $entry];
        }

        $out = [];
        foreach ($byDimension as $dim => $sources) {
            $volume = collect($sources)->first(fn (array $s): bool => $s['volume'] !== null);
            // The performance source must be a DIFFERENT object (else it's a
            // single-object chart, not a join).
            $perf = collect($sources)->first(
                fn (array $s): bool => $s['perf'] !== null && $volume !== null && $s['object_id'] !== $volume['object_id'],
            );
            if ($volume === null || $perf === null) {
                continue;
            }

            $finding = $this->relate($dim, $volume, $perf, $es);
            if ($finding !== null) {
                $out[] = $finding;
            }
            if (count($out) >= 2) {
                break; // premium findings — a couple is plenty
            }
        }

        return $out;
    }

    /**
     * The (dimension, volume measure, performance measure) a source offers.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, string>  $names
     * @return array{object_id: string, object: array<string, mixed>, dim: string, dimField: array<string, mixed>, volume: array<string, mixed>|null, perf: array<string, mixed>|null}|null
     */
    private function shapeOf(array $object, array $names, string $hint): ?array
    {
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $dimField = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['string', 'single_select'], true)
            && preg_match('/label|bucket|_id$|^id$/i', (string) ($f['slug'] ?? '')) !== 1);
        if ($dimField === null) {
            return null;
        }
        $dimName = $names[$dimField['id']] ?? '';
        // A generic field name ("Key") hides which dimension it is — the
        // object's distinguishing hint names it (mirrors the recommender dedup).
        if ($dimName === '' || preg_match('/^(key|clave|dimension|dimensi\w*|grupo|group|valor|value|name|nombre)$/', $dimName) === 1) {
            $dimName = $hint !== '' ? $hint : $dimName;
        }

        $volume = $perf = null;
        foreach ($fields as $f) {
            if (! in_array($f['type'] ?? '', ['number', 'currency'], true)) {
                continue;
            }
            $type = $this->semantics->measureTypeOf($f);
            if ($volume === null && $type === SemanticProfile::MEASURE_ADDITIVE) {
                $volume = $f;
            }
            if ($perf === null && $type === SemanticProfile::MEASURE_RATIO) {
                $perf = $f;
            }
        }

        return [
            'object_id' => (string) ($object['id'] ?? ''),
            'object' => $object,
            'dim' => $dimName,
            'dimField' => $dimField,
            'volume' => $volume,
            'perf' => $perf,
        ];
    }

    /**
     * Join the two sources on the dimension value and read whether high volume
     * comes with low performance.
     *
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $perf
     * @return array<string, mixed>|null
     */
    private function relate(string $dim, array $volume, array $perf, bool $es): ?array
    {
        $vols = $this->sumByCategory($volume['entry'], $volume['dimField'], $volume['volume']);
        $perfs = $this->avgByCategory($perf['entry'], $perf['dimField'], $perf['perf']);
        $shared = array_intersect_key($vols, $perfs);
        if (count($shared) < self::MIN_SHARED) {
            return null;
        }

        arsort($shared); // by volume, desc
        $cats = array_keys($shared);
        $topN = min(3, intdiv(count($cats), 2));
        $top = array_slice($cats, 0, $topN);
        $rest = array_slice($cats, $topN);

        $avg = fn (array $keys): float => $keys === []
            ? 0.0
            : array_sum(array_map(fn ($k) => $perfs[$k], $keys)) / count($keys);
        $topPerf = round($avg($top), 1);
        $restPerf = round($avg($rest), 1);
        $gap = round($restPerf - $topPerf, 1);

        // Only a MEANINGFUL contrast is worth stating.
        if (abs($gap) < 5) {
            return null;
        }

        $volName = Str::lower((string) ($volume['volume']['name'] ?? 'volumen'));
        $perfName = (string) ($perf['perf']['name'] ?? ($es ? 'desempeño' : 'performance'));
        $worse = $gap > 0; // rest performs better → top (high volume) is worse

        $why = $es
            ? "Los {$topN} ".Str::lower($dim)." de mayor {$volName} promedian {$topPerf}% de {$perfName} vs {$restPerf}% del resto — "
                .($worse ? 'ahí es donde más rinde mejorar.' : 'el volumen no está arrastrando el desempeño.')
            : "The top {$topN} ".Str::lower($dim)." by {$volName} average {$topPerf}% {$perfName} vs {$restPerf}% for the rest — "
                .($worse ? 'that\'s where improvement pays most.' : 'volume isn\'t dragging performance.');

        return [
            'kind' => 'cross',
            'kicker' => ($es ? 'Cruce · ' : 'Join · ').Str::upper(Str::limit($dim, 14, '')),
            'title' => $es
                ? Str::ucfirst($volName).' vs. '.Str::lower($perfName)
                : Str::ucfirst($volName).' vs. '.Str::lower($perfName),
            'why' => $why,
            // Added as an insight — the value is in the join, not a single viz.
            'insight' => [
                'type' => 'insight',
                'title' => $es ? Str::ucfirst($volName).' vs. '.$perfName : Str::ucfirst($volName).' vs. '.$perfName,
                'body' => $why,
                'variant' => 'conclusion',
            ],
            // Scatter of (volume, performance) over the shared categories.
            'preview' => [
                'kind' => 'scatter',
                'points' => array_values(array_map(
                    fn ($cat): array => [round((float) $vols[$cat], 2), round((float) $perfs[$cat], 2)],
                    $cats,
                )),
            ],
            'base' => 108, // the cross-source read outranks single cuts
            'flag' => $worse ? ['tone' => 'hot', 'text' => ($es ? $gap.' pts de brecha' : $gap.' pts gap')] : null,
            'dim' => $dim,
        ];
    }

    /**
     * @param  array{object: array<string, mixed>, rows: list<array<string, mixed>>}  $entry
     * @param  array<string, mixed>  $dimField
     * @param  array<string, mixed>  $measure
     * @return array<string, float>
     */
    private function sumByCategory(array $entry, array $dimField, array $measure): array
    {
        [$dimPath, $numPath] = $this->paths($entry['object'], $dimField, $measure);
        $sums = [];
        foreach ($entry['rows'] as $row) {
            $cat = data_get($row, $dimPath);
            $val = data_get($row, $numPath);
            if (is_scalar($cat) && trim((string) $cat) !== '' && is_numeric($val)) {
                $sums[(string) $cat] = ($sums[(string) $cat] ?? 0) + (float) $val;
            }
        }

        return $sums;
    }

    /**
     * @param  array{object: array<string, mixed>, rows: list<array<string, mixed>>}  $entry
     * @param  array<string, mixed>  $dimField
     * @param  array<string, mixed>  $measure
     * @return array<string, float>
     */
    private function avgByCategory(array $entry, array $dimField, array $measure): array
    {
        [$dimPath, $numPath] = $this->paths($entry['object'], $dimField, $measure);
        $acc = [];
        foreach ($entry['rows'] as $row) {
            $cat = data_get($row, $dimPath);
            $val = data_get($row, $numPath);
            if (is_scalar($cat) && trim((string) $cat) !== '' && is_numeric($val)) {
                $acc[(string) $cat][] = (float) $val;
            }
        }

        return array_map(fn (array $vs): float => array_sum($vs) / count($vs), $acc);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $dimField
     * @param  array<string, mixed>  $measure
     * @return array{0: string, 1: string}
     */
    private function paths(array $object, array $dimField, array $measure): array
    {
        $index = collect($object['source']['field_map'] ?? [])->pluck('external_path', 'field_id')->all();

        return [
            $index[$dimField['id']] ?? ($dimField['slug'] ?? ''),
            $index[$measure['id']] ?? ($measure['slug'] ?? ''),
        ];
    }
}
