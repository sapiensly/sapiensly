<?php

namespace App\Services\Manifest;

use Illuminate\Support\Str;

/**
 * Mints the `id` the manifest schema requires on nodes a propose_change op adds,
 * when the model omitted it — so an agent can write smaller, simpler patches and
 * never trips the "id is required" / "id pattern" class of errors.
 *
 * It is deliberately TYPE-AWARE and conservative: it only fills ids on node
 * shapes that REQUIRE one (objects, fields, options, pages, blocks, table
 * columns, metric/funnel items, tabs, sections, workflows, steps) and never on
 * the many `additionalProperties:false` shapes that forbid an `id` (form field
 * refs, related_list/card_grid columns & meta_fields, data_source, style,
 * actions, …). Existing ids are left untouched.
 */
class ManifestIdFiller
{
    /**
     * Fill missing ids in the `value` of every add/replace op.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    public static function fill(array $ops): array
    {
        foreach ($ops as &$op) {
            if (! is_array($op) || ! in_array($op['op'] ?? null, ['add', 'replace'], true)) {
                continue;
            }
            if (! array_key_exists('value', $op) || ! is_array($op['value'])) {
                continue;
            }
            $op['value'] = self::node($op['value'], self::prefixForPath((string) ($op['path'] ?? '')));
        }
        unset($op);

        return $ops;
    }

    /**
     * The id prefix for the node added at a path's target collection, or null
     * when the target isn't an id-bearing collection (so we don't touch it).
     * `fields` only counts under /objects (a field DEFINITION); a form block's
     * `fields` (references) and `columns` (ambiguous: table vs related_list) are
     * left to the type-aware recursion / the model.
     */
    private static function prefixForPath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        if ($segments === []) {
            return null;
        }
        $last = $segments[count($segments) - 1];
        // For an append (/-) or insert (/N) the collection key is the prior segment.
        $key = ($last === '-' || ctype_digit($last))
            ? ($segments[count($segments) - 2] ?? '')
            : $last;

        return match ($key) {
            'objects' => 'obj',
            'pages' => 'pag',
            'blocks', 'left_blocks', 'right_blocks' => 'blk',
            'options' => 'opt',
            'items' => 'itm',
            'stages' => 'stg',
            'tabs' => 'tab',
            'sections' => 'sec',
            'workflows' => 'wfl',
            'steps' => 'stp',
            'fields' => in_array('objects', $segments, true) ? 'fld' : null,
            default => null,
        };
    }

    /**
     * Ensure ids on a node and its id-bearing descendants. A list is mapped
     * element-wise (each element gets $selfPrefix). An object node gets an id
     * (when $selfPrefix is set and it lacks one), then recurses ONLY the child
     * collections that are themselves id-bearing — gated by the node's own type
     * so ambiguous shapes (form fields, related_list columns) are never touched.
     */
    private static function node(mixed $value, ?string $selfPrefix): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => self::node($item, $selfPrefix), $value);
        }

        if ($selfPrefix !== null && ! isset($value['id'])) {
            $value['id'] = self::mint($selfPrefix);
        }

        $type = $value['type'] ?? null;

        // Always-id-bearing child collections (block trees + selects + metric/funnel).
        foreach (['blocks' => 'blk', 'left_blocks' => 'blk', 'right_blocks' => 'blk', 'tabs' => 'tab', 'sections' => 'sec', 'options' => 'opt', 'items' => 'itm', 'stages' => 'stg'] as $key => $prefix) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $value[$key] = self::node($value[$key], $prefix);
            }
        }

        // Table columns require ids; related_list/card_grid columns/meta do not.
        if ($type === 'table' && isset($value['columns']) && is_array($value['columns'])) {
            $value['columns'] = self::node($value['columns'], 'col');
        }

        // An object node (has fields, carries no block/field `type`) — its fields
        // are DEFINITIONS that need ids (and their options, via the loop above).
        if ($type === null && isset($value['fields']) && is_array($value['fields'])) {
            $value['fields'] = self::node($value['fields'], 'fld');
        }

        return $value;
    }

    private static function mint(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }
}
