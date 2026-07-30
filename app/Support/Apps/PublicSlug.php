<?php

namespace App\Support\Apps;

use App\Models\App;
use Illuminate\Support\Str;

/**
 * Mints the globally-unique slug an app lives under once it goes public.
 *
 * App slugs are unique per-organization; the public namespace is shared by
 * every tenant, so the two cannot be the same string by construction. It is
 * also kebab-case: an app slug uses underscores, but a public URL reads
 * /a/cafe-nebula, not /a/cafe_nebula.
 *
 * Shared by the landing publisher and the portal publisher so both surfaces
 * draw from ONE namespace — a landing and a portal can never mint the same
 * slug and shadow each other.
 */
final class PublicSlug
{
    /**
     * The slug for an app, stable once minted: an already-public app keeps the
     * slug it has (republishing must never change a URL people have shared).
     */
    public static function mint(App $app, string $fallback): string
    {
        if ($app->public_slug !== null) {
            return $app->public_slug;
        }

        $base = Str::slug(str_replace('_', '-', $app->slug));
        if ($base === '') {
            $base = $fallback;
        }

        $candidate = $base;
        $n = 2;
        while (App::query()->where('public_slug', $candidate)->exists()) {
            $candidate = "{$base}-{$n}";
            $n++;
        }

        return $candidate;
    }
}
