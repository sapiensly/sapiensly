<?php

namespace App\Services\Analyst;

use Illuminate\Support\Str;

/**
 * What an analysis SHOWS, independent of how it's drawn or which object backs
 * it — the vocabulary the analyst dedupes in.
 *
 * A board carries the same cut under several overlapping sources (reason, cause
 * and key breakdowns of the same tickets), so «Total Tickets by Reason» must be
 * recognised as already shown whichever object backs it, whether it's drawn as a
 * pareto or a bar, summed or counted. Keys collapse all of that:
 * `{family}|{measure}|{dimension}`, in field NAMES rather than ids.
 *
 * Shared by the core (which drops a candidate the surface already shows) and by
 * each surface adapter (which reads its own artifacts — manifest blocks, deck
 * slides — into the same vocabulary).
 */
class SemanticKey
{
    /** A dimension field whose name says nothing about WHICH dimension it is. */
    private const GENERIC_DIM = '/^(key|clave|dimension|dimensi\w*|grupo|group|valor|value|name|nombre)$/';

    /**
     * @param  array<string, mixed>  $chart
     * @param  array<string, string>  $names  field_id → normalised name
     * @param  array<string, string>  $hints  object_id → distinguishing token
     */
    public static function forChart(string $objectId, array $chart, array $names, array $hints): string
    {
        if (($chart['__gauge'] ?? false) === true) {
            return 'gauge|'.($names[$chart['field_id'] ?? ''] ?? '').'|';
        }
        // A cohort table is not a cut of a measure at all — it is a reading of one
        // intake against another, so it keys on the two dates that define it.
        if (($chart['__pivot'] ?? false) === true) {
            return 'cohort|'
                .($names[$chart['group_by_field_id'] ?? ''] ?? '').'|'
                .($names[$chart['column_field_id'] ?? ''] ?? '');
        }

        $type = $chart['chart_type'] ?? '';
        $measure = $names[$chart['y_field_id'] ?? ''] ?? 'count';

        // A scatter says «these two relate», which is symmetric: x vs y and y vs
        // x are the same finding, so the pair is sorted rather than ordered.
        if ($type === 'scatter') {
            $pair = [
                $names[$chart['x_field_id'] ?? ''] ?? '',
                $names[$chart['y_field_id'] ?? ''] ?? '',
            ];
            sort($pair);

            return 'correlation|'.$pair[0].'|'.$pair[1];
        }

        // A flow is DIRECTIONAL — reason→owner is not owner→reason — so unlike a
        // correlation the pair keeps its order.
        if ($type === 'sankey') {
            return 'flow|'
                .self::dimName($chart['group_by_field_id'] ?? '', $objectId, $names, $hints).'|'
                .($names[$chart['series_field_id'] ?? ''] ?? '');
        }

        // The spread of a measure within a category is a different question from
        // its total there — a box and a bar of the same cut don't say the same
        // thing, so they must not dedupe against each other.
        if ($type === 'box') {
            return 'distribution|'.$measure.'|'
                .self::dimName($chart['group_by_field_id'] ?? '', $objectId, $names, $hints);
        }

        // A second categorical splits the measure INSIDE each column — «what is it
        // made of», not «how much is there».
        if (isset($chart['series_field_id'])) {
            return 'composition|'.$measure.'|'.($names[$chart['series_field_id']] ?? '');
        }

        // Seasonality is the same measure over time read at a coarser grain: a
        // quarterly cut answers a question the weekly one cannot, so it is its
        // own finding rather than a duplicate trend.
        if (in_array($chart['bucket'] ?? '', ['quarter', 'year'], true)) {
            return 'seasonality|'.$measure.'|'.$chart['bucket'];
        }

        $family = in_array($type, ['area', 'line'], true)
            ? 'trend'
            : 'breakdown';
        if ($family === 'trend') {
            return 'trend|'.$measure.'|time';
        }

        return 'breakdown|'.$measure.'|'
            .self::dimName($chart['group_by_field_id'] ?? '', $objectId, $names, $hints);
    }

    /**
     * A dimension's name — falling back to the object's distinguishing hint when
     * the field is generically named ("Key" says nothing about WHICH dimension).
     *
     * @param  array<string, string>  $names
     * @param  array<string, string>  $hints
     */
    private static function dimName(string $fieldId, string $objectId, array $names, array $hints): string
    {
        $dim = $names[$fieldId] ?? '';
        if ($dim === '' || preg_match(self::GENERIC_DIM, $dim) === 1) {
            $dim = $hints[$objectId] ?? $dim;
        }

        return $dim;
    }

    /**
     * field_id → normalised field NAME across every object — so the same measure
     * or dimension matches across overlapping sources and id renames.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    public static function fieldNames(array $manifest): array
    {
        $out = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            foreach ($object['fields'] ?? [] as $f) {
                if (is_array($f) && isset($f['id'])) {
                    $out[$f['id']] = Str::lower(Str::ascii((string) ($f['name'] ?? $f['slug'] ?? '')));
                }
            }
        }

        return $out;
    }

    /**
     * objectId → the token that DISTINGUISHES the object among overlapping
     * sources: the name's "·" suffix ("Tickets By Dimension · Reason" → reason)
     * or the slug's last segment. Used to name a generic dimension field.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    public static function objectHints(array $manifest): array
    {
        $out = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            $name = (string) ($object['name'] ?? '');
            $hint = str_contains($name, '·')
                ? Str::afterLast($name, '·')
                : Str::afterLast((string) ($object['slug'] ?? ''), '_');
            $out[(string) ($object['id'] ?? '')] = Str::lower(Str::ascii(trim($hint)));
        }

        return $out;
    }
}
