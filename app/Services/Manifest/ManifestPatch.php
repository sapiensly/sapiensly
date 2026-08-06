<?php

namespace App\Services\Manifest;

use gamringer\JSONPatch\Exception as PatchException;
use gamringer\JSONPatch\Patch;

/**
 * Applies an RFC 6902 JSON Patch to a manifest array.
 *
 * Normalises the document first so optional top-level collections exist as
 * arrays. Without this, the underlying library silently DROPS an append like
 * `add /workflows/-` when the `workflows` key is absent (a brand-new app with
 * no automations yet): the patch reports success but the value vanishes, which
 * then surfaces far downstream as an "unknown workflow" reference. Seeding the
 * empty array makes the natural `/workflows/-` append work — important because
 * both the builder AI's propose_change and AppManifestService::applyPatch route
 * through here.
 *
 * It also works around a bug in gamringer/php-json-pointer: inserting a value
 * at a *numeric* array index (e.g. `add /pages/0/blocks/1`) reaches
 * `ReferencedValue::insertValue()`, which calls
 * `array_splice($owner, $index, 0, $value)` WITHOUT wrapping `$value` in an
 * array. PHP's array_splice then spreads an object/array replacement into its
 * own elements, so an inserted block `{id,type}` lands as two stray scalars and
 * the original element shifts — surfacing downstream as
 * `data (string) must match type: object`. Appends (`/-`) take a different code
 * path and are unaffected, which is why append "works" but mid-array insert
 * corrupts the manifest. We intercept every insert into an indexed array and
 * splice correctly; all other ops still go through the library verbatim.
 */
final class ManifestPatch
{
    /** Optional top-level array containers that may be absent on a fresh manifest. */
    private const OPTIONAL_ARRAYS = ['workflows'];

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, mixed>
     */
    public static function apply(array $document, array $ops): array
    {
        foreach (self::OPTIONAL_ARRAYS as $key) {
            if (! array_key_exists($key, $document)) {
                $document[$key] = [];
            }
        }

        $target = json_decode(json_encode($document, JSON_THROW_ON_ERROR));

        foreach ($ops as $index => $op) {
            // Ops are applied one at a time, so the one that fails is known
            // here — the only place it ever is. Losing that (and the library's
            // real cause, which it buries in `previous`) is what turns a
            // one-character address mistake into a blind retry loop.
            try {
                if (($op['op'] ?? null) === 'append') {
                    self::applyAppend($target, $op);

                    continue;
                }

                if (self::isIndexedArrayInsert($target, $op)) {
                    self::applyInsert($target, $op);

                    continue;
                }

                // A `replace` that means `add`. RFC 6902 requires a replace
                // target to already exist, and the builder writes `replace`
                // whenever it is SETTING a property — five of one build's
                // rejections were this, every one of them a `replace` on a
                // property the node simply did not carry yet.
                //
                // It is not a cosmetic failure. One of the five was
                // `replace /objects/2/fields/0/capture`, the op that would have
                // made the asset code scannable; it was refused, `capture` was
                // never set, and the closing critic then reported the scannable
                // code as MISSING — a review turn bought by a distinction the
                // author never intended to draw.
                //
                // Only a MAP member is coerced. An out-of-range array index
                // stays an error: "/pages/17" on a 17-page app is a real
                // mistake about where something lives, and silently appending
                // would land it somewhere nobody asked for.
                if (self::isReplaceOfAbsentProperty($target, $op)) {
                    $op['op'] = 'add';
                }

                $patch = Patch::fromJSON(json_encode([$op], JSON_THROW_ON_ERROR));
                $patch->apply($target);
            } catch (PatchException $e) {
                // ONLY the library's opaque failure is rewritten. The deliberate
                // rules enforced above (append refusing markup, and friends)
                // already say exactly what is wrong and must reach the caller
                // with their own type and wording intact.
                throw self::describe((int) $index, is_array($op) ? $op : [], $e, $target);
            }
        }

        return json_decode(json_encode($target, JSON_THROW_ON_ERROR), true);
    }

    /**
     * The paths a set of ops TOUCHES, with array-append (`/-`) ops resolved to the
     * concrete index the value landed at — so a caller knows where its addition
     * went (e.g. `/objects/3`) without re-reading the whole manifest to find out.
     * `$before` is the pre-patch document; sequential appends to the same array
     * are counted so each gets its own index. Non-append ops are reported verbatim.
     *
     * @param  list<array<string, mixed>>  $ops
     * @param  array<string, mixed>  $before
     * @return list<array{op: string, path: string, from?: string}>
     */
    public static function changedPaths(array $ops, array $before): array
    {
        $appendsSoFar = [];
        $changed = [];

        foreach ($ops as $op) {
            if (! is_array($op)) {
                continue;
            }
            $type = (string) ($op['op'] ?? '');
            $path = (string) ($op['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $resolved = $path;
            if (str_ends_with($path, '/-')) {
                $parent = substr($path, 0, -2);
                $existing = self::resolve($before, self::tokens($parent));
                $baseLength = is_array($existing) ? count($existing) : 0;
                $n = $appendsSoFar[$parent] ?? 0;
                $resolved = $parent.'/'.($baseLength + $n);
                $appendsSoFar[$parent] = $n + 1;
            }

            $entry = ['op' => $type, 'path' => $resolved];
            if ($resolved !== $path) {
                $entry['from'] = $path;
            }
            $changed[] = $entry;
        }

        return $changed;
    }

    /**
     * True when a `replace` addresses a property its (object) parent does not
     * have yet — the shape that means `add`.
     *
     * @param  array<string, mixed>  $op
     */
    private static function isReplaceOfAbsentProperty(mixed $target, array $op): bool
    {
        if (($op['op'] ?? null) !== 'replace' || ! array_key_exists('value', $op)) {
            return false;
        }

        $tokens = self::tokens((string) ($op['path'] ?? ''));
        if ($tokens === []) {
            return false;
        }

        $key = array_pop($tokens);
        $parent = self::resolve($target, $tokens);

        // json_decode without assoc gives maps as objects and lists as arrays,
        // so this distinguishes the two without guessing.
        return is_object($parent) && ! property_exists($parent, $key);
    }

    /**
     * True when the op inserts into an indexed (list) array at a numeric index —
     * the only case the library mishandles. Appends (`/-`), replaces, removes,
     * and writes to object properties all stay on the library path.
     *
     * @param  array<string, mixed>  $op
     */
    private static function isIndexedArrayInsert(mixed $target, array $op): bool
    {
        if (! in_array($op['op'] ?? null, ['add', 'move', 'copy'], true)) {
            return false;
        }

        $tokens = self::tokens($op['path'] ?? '');
        if ($tokens === []) {
            return false;
        }

        $last = array_pop($tokens);
        if (filter_var($last, FILTER_VALIDATE_INT) === false || (int) $last < 0) {
            return false;
        }

        $parent = self::resolve($target, $tokens);

        return is_array($parent) && array_is_list($parent);
    }

    /**
     * Insert a value into an indexed array at a numeric index, preserving the
     * value as a single element (the fix for the library's spread bug).
     *
     * @param  array<string, mixed>  $op
     */
    private static function applyInsert(mixed &$target, array $op): void
    {
        $tokens = self::tokens($op['path']);
        $index = (int) array_pop($tokens);

        $value = match ($op['op']) {
            'add' => self::toNode($op['value'] ?? null),
            'copy' => self::clone(self::resolve($target, self::tokens($op['from'] ?? ''))),
            'move' => self::detach($target, self::tokens($op['from'] ?? '')),
            default => null,
        };

        $parent = &self::reference($target, $tokens);
        $count = count($parent);
        if ($index > $count) {
            $index = $count;
        }

        array_splice($parent, $index, 0, [$value]);
    }

    /**
     * The one extension over RFC 6902: `{op:"append", path, value}` concatenates
     * a string onto the string at `path` (absent/null leaf starts from "").
     * A `replace` on a LONG string (settings.custom_css can run 60k chars on a
     * landing) forces the author to resend the ENTIRE value on every revision —
     * which is exactly the tool-argument size that truncates in transit and
     * forces patch-splitting. Append lets long text be written and revised in
     * small chunks (CSS's cascade makes appended overrides a natural revision
     * idiom). Ops apply sequentially, so consecutive appends to the same path
     * stack within one call.
     *
     * @param  array<string, mixed>  $op
     */
    private static function applyAppend(mixed &$target, array $op): void
    {
        $path = (string) ($op['path'] ?? '');
        $value = $op['value'] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('append requires a string `value`.');
        }

        $tokens = self::tokens($path);
        if ($tokens === []) {
            throw new \InvalidArgumentException('append cannot target the document root.');
        }

        // MARKUP CANNOT BE STREAMED. Every save re-parses and REPAIRS a block's
        // html (LandingHtmlSanitizer / HtmlSanitizer), so a partial first chunk
        // comes back with its open tags closed — and the next append lands as a
        // sibling AFTER the element it belonged to, not inside it. Cutting on
        // element boundaries doesn't help: the ancestors are still open at the
        // cut. It fails silently (every patch reports success, the manifest
        // reads fine), so it has to be refused here rather than discovered in
        // pixels. Observed: half a logo marquee rendering as a stray row below
        // its own track, after six "successful" patches.
        if (str_starts_with($path, '/pages/') && str_ends_with($path, '/content')) {
            throw new \InvalidArgumentException(
                "append cannot write a block's `content`: the markup is re-parsed and repaired on every save, "
                .'so a partial chunk gets its open tags closed and everything appended after it lands outside '
                .'the element. Send the whole content in one `add` op — a large value is fine.'
            );
        }

        $parentTokens = $tokens;
        $leaf = array_pop($parentTokens);
        $parent = self::resolve($target, $parentTokens);
        if (! is_object($parent) && ! is_array($parent)) {
            throw new \InvalidArgumentException("append: the parent of '{$path}' does not exist — add it first.");
        }

        $existing = self::resolve($target, $tokens);
        if ($existing !== null && ! is_string($existing)) {
            throw new \InvalidArgumentException("append only works on string values — '{$path}' holds ".gettype($existing).'.');
        }

        $ref = &self::reference($target, $tokens);
        $ref = ($existing ?? '').$value;
        unset($ref, $leaf);
    }

    /**
     * Remove and return the value at a pointer (used by `move`), so a later
     * insert sees the post-removal array — matching RFC 6902 "remove then add".
     */
    private static function detach(mixed &$target, array $tokens): mixed
    {
        $key = array_pop($tokens);
        $parent = &self::reference($target, $tokens);

        if (is_array($parent)) {
            $removed = $parent[$key];
            array_splice($parent, (int) $key, 1);

            return $removed;
        }

        $removed = $parent->{$key};
        unset($parent->{$key});

        return $removed;
    }

    /**
     * Resolve a pointer to a value (read-only copy).
     */
    private static function resolve(mixed $target, array $tokens): mixed
    {
        foreach ($tokens as $token) {
            if (is_array($target)) {
                $target = $target[$token] ?? null;
            } elseif (is_object($target)) {
                $target = $target->{$token} ?? null;
            } else {
                return null;
            }
        }

        return $target;
    }

    /**
     * Resolve a pointer to a by-reference handle on the live tree.
     */
    private static function &reference(mixed &$target, array $tokens): mixed
    {
        $ref = &$target;
        foreach ($tokens as $token) {
            if (is_array($ref)) {
                $ref = &$ref[$token];
            } else {
                $ref = &$ref->{$token};
            }
        }

        return $ref;
    }

    /**
     * Turn a library failure into something a caller can act on.
     *
     * @param  array<string, mixed>  $op
     */
    private static function describe(int $index, array $op, PatchException $e, mixed $target): ManifestPatchException
    {
        // The library's own message is always "An Operation failed"; the useful
        // sentence ("Referenced value does not exist", "Target value does not
        // match expected value") is one level down.
        $reason = $e->getPrevious()?->getMessage() ?: $e->getMessage();

        return new ManifestPatchException(
            $index,
            (string) ($op['op'] ?? ''),
            (string) ($op['path'] ?? ''),
            $reason,
            self::locate($target, (string) ($op['path'] ?? '')),
        );
    }

    /**
     * Where a path stops resolving, and what is actually there.
     *
     * "Referenced value does not exist" leaves the author guessing which
     * segment was wrong. Walking the pointer until it breaks turns that into
     * the one fact they need: the deepest part that DID resolve, and — when it
     * is a list — how many items it holds, which is almost always the answer
     * (an index counted by eye off a truncated read).
     */
    private static function locate(mixed $target, string $path): ?string
    {
        $tokens = self::tokens($path);

        if ($tokens === []) {
            return null;
        }

        $node = $target;
        $resolved = '';

        foreach ($tokens as $token) {
            if (is_array($node)) {
                if ($token !== '-' && ctype_digit($token) && array_key_exists((int) $token, $node)) {
                    $node = $node[(int) $token];
                    $resolved .= '/'.$token;

                    continue;
                }

                $where = $resolved === '' ? 'the document root' : "\"{$resolved}\"";

                return "{$where} holds ".count($node)." item(s), so \"{$token}\" is out of range";
            }

            if ($node instanceof \stdClass) {
                if (property_exists($node, $token)) {
                    $node = $node->{$token};
                    $resolved .= '/'.$token;

                    continue;
                }

                $keys = array_keys(get_object_vars($node));
                $where = $resolved === '' ? 'the document root' : "\"{$resolved}\"";

                return "{$where} has no \"{$token}\"".
                    ($keys === [] ? '' : ' (it has: '.implode(', ', array_slice($keys, 0, 8)).')');
            }

            return "\"{$resolved}\" is a scalar, so \"{$token}\" cannot be addressed";
        }

        return null;
    }

    /**
     * Decode a JSON pointer into unescaped tokens (drops the leading empty token).
     *
     * @return list<string>
     */
    private static function tokens(string $pointer): array
    {
        if ($pointer === '' || $pointer === '/') {
            return [];
        }

        $tokens = explode('/', ltrim($pointer, '/'));

        return array_map(
            static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            $tokens,
        );
    }

    /**
     * Normalise a PHP value into the stdClass/array shape used inside $target,
     * so objects inserted from ops stay objects (not associative arrays).
     */
    private static function toNode(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private static function clone(mixed $value): mixed
    {
        return self::toNode($value);
    }
}
