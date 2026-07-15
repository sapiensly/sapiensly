<?php

namespace App\Services\Records;

use App\Services\Connected\ConnectedObjectReader;
use Illuminate\Support\Arr;

/**
 * Where a field's value lives inside a row — the one place that knows the two
 * row shapes the platform produces.
 *
 * A connected object's row is the external system's payload verbatim, so a field
 * is addressed by the `external_path` its field_map declares (possibly nested:
 * `attributes.reason`). An internal object's row is slug-keyed by the record
 * store, so the same field is addressed by its bare `slug`. Both are `data_get()`
 * paths over the payload {@see ObjectRowSource} hands back, which is what lets
 * the analytic primitives read either source without branching on it.
 *
 * Before this existed, each primitive re-derived the map from `field_map` and
 * fell back to the bare slug — right by accident for internal objects, but the
 * analytic layer never sampled them anyway, so native data was invisible to it.
 */
class FieldPaths
{
    /**
     * Every field the object exposes, as field_id => data_get path.
     *
     * The field_map — not `source.type` — is the authority on where a value
     * lives: an internal object never has one, and a connected object that
     * declares one means it. Without a map, a field is its slug.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, string>
     */
    public static function forObject(array $object): array
    {
        $mapped = collect($object['source']['field_map'] ?? [])
            ->pluck('external_path', 'field_id')
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->all();

        if ($mapped !== []) {
            return $mapped;
        }

        $paths = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $id = $field['id'] ?? null;
            $slug = $field['slug'] ?? null;
            if (is_string($id) && is_string($slug) && $slug !== '') {
                $paths[$id] = $slug;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public static function isConnected(array $object): bool
    {
        return ($object['source']['type'] ?? 'internal') === 'connected';
    }

    /**
     * Make a connected object's rows honour the contract above: addressable by
     * external_path. {@see ConnectedObjectReader::mapRow}
     * actually FLATTENS each external row to manifest slugs (external_path →
     * slug), so a nested external_path ("totals.total_tickets") no longer
     * resolves — data_get reads the dot as nesting and misses the flat slug key
     * ("totals_total_tickets"), and every analytic primitive that addresses by
     * {@see forObject} reads the column as empty. Rebuild the nesting from the
     * field_map (slug value → external_path) WITHOUT dropping the slug keys, so
     * both an external_path read and a bare-slug fallback resolve. A no-op for
     * flat paths (== slug) and for internal objects (no field_map).
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function restoreExternalShape(array $object, array $rows): array
    {
        $map = $object['source']['field_map'] ?? null;
        if (! is_array($map) || $map === []) {
            return $rows; // internal object — already slug-keyed, no external paths
        }

        $slugById = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['id'], $field['slug'])) {
                $slugById[$field['id']] = $field['slug'];
            }
        }

        // Only NESTED external paths need rebuilding — a flat path already equals
        // its slug and resolves as-is.
        $pairs = [];
        foreach ($map as $entry) {
            $path = is_array($entry) ? ($entry['external_path'] ?? null) : null;
            $slug = is_array($entry) ? ($slugById[$entry['field_id'] ?? ''] ?? null) : null;
            if (is_string($path) && is_string($slug) && str_contains($path, '.')) {
                $pairs[] = [$path, $slug];
            }
        }
        if ($pairs === []) {
            return $rows;
        }

        return array_map(function (array $row) use ($pairs): array {
            foreach ($pairs as [$path, $slug]) {
                if (array_key_exists($slug, $row) && data_get($row, $path) === null) {
                    Arr::set($row, $path, $row[$slug]);
                }
            }

            return $row;
        }, $rows);
    }
}
