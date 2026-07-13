<?php

namespace App\Services\Records;

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
}
