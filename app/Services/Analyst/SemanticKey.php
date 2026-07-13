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
        // A scatter says «these two relate», which is symmetric: x vs y and y vs
        // x are the same finding, so the pair is sorted rather than ordered.
        if (($chart['chart_type'] ?? '') === 'scatter') {
            $pair = [
                $names[$chart['x_field_id'] ?? ''] ?? '',
                $names[$chart['y_field_id'] ?? ''] ?? '',
            ];
            sort($pair);

            return 'correlation|'.$pair[0].'|'.$pair[1];
        }
        $family = in_array($chart['chart_type'] ?? '', ['area', 'line'], true)
            ? 'trend'
            : 'breakdown';
        $measure = $names[$chart['y_field_id'] ?? ''] ?? 'count';
        if ($family === 'trend') {
            return 'trend|'.$measure.'|time';
        }
        $dim = $names[$chart['group_by_field_id'] ?? ''] ?? '';
        if ($dim === '' || preg_match(self::GENERIC_DIM, $dim) === 1) {
            $dim = $hints[$objectId] ?? $dim;
        }

        return 'breakdown|'.$measure.'|'.$dim;
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
