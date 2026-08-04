<?php

namespace App\Support\Apps;

use Illuminate\Support\Str;

/**
 * Rewrites every id in a manifest to a fresh one, consistently.
 *
 * Two apps installed from the same package must share no identifiers. Not for
 * correctness of storage — records are keyed by (app, object) so a collision
 * would be harmless there — but because ids leak into every other surface:
 * versions, diffs, audit summaries, a builder turn's patch paths. Two apps
 * whose objects answer to the same id make every one of those ambiguous to
 * read, and that ambiguity is the kind that is discovered late.
 *
 * The remap is done on the STRINGS rather than by walking known reference
 * fields, because references are everywhere — a policy's role_id, a block's
 * object_id, a column's field_id, an expression's `{{...}}`, a workflow step's
 * target. Mapping every occurrence of an id to the same replacement keeps the
 * manifest internally consistent without needing to know where each one lives.
 */
final class ManifestIdRemap
{
    /**
     * Prefixes the manifest mints. `app` is deliberately absent — the app's own
     * id is assigned by the installation, not derived here.
     */
    private const PREFIXES = [
        'obj', 'fld', 'pag', 'blk', 'col', 'opt', 'rol', 'wkf', 'wfl', 'stp', 'mod', 'tab', 'sec', 'itm', 'nav',
    ];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function apply(array $manifest): array
    {
        $map = [];

        return self::walk($manifest, $map);
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function walk(mixed $node, array &$map): mixed
    {
        if (is_string($node)) {
            return self::rewrite($node, $map);
        }

        if (! is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            // Keys too, not only values. An id can BE a key — a record_form's
            // `submission.values` and `participation.values` are keyed by the
            // field they write to — and a key left behind points at an object
            // that no longer exists, so the write fails with "unknown field"
            // on an app that installed without a word of complaint.
            $out[is_string($key) ? self::rewrite($key, $map) : $key] = self::walk($value, $map);
        }

        return $out;
    }

    /**
     * Replace every id occurring anywhere in a string — a bare id, and one
     * embedded in an expression like "{{steps.stp_x.output}}" — with its
     * counterpart. The same source id always yields the same replacement.
     *
     * @param  array<string, string>  $map
     */
    private static function rewrite(string $value, array &$map): string
    {
        $pattern = '/\b('.implode('|', self::PREFIXES).')_([a-z0-9]{6,60})\b/';

        return (string) preg_replace_callback(
            $pattern,
            function (array $m) use (&$map): string {
                $original = $m[0];
                if (! isset($map[$original])) {
                    $map[$original] = $m[1].'_'.strtolower((string) Str::ulid());
                }

                return $map[$original];
            },
            $value,
        );
    }
}
