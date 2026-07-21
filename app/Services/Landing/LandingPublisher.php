<?php

namespace App\Services\Landing;

use App\Enums\AppKind;
use App\Models\App;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single publish/unpublish path for landings — shared by the MCP
 * publish_landing tool and the builder UI so both surfaces enforce the same
 * gate: only a landing can go public, the public slug is GLOBALLY unique
 * (app slugs are only per-org), and unpublishing makes the public URL 404.
 */
class LandingPublisher
{
    /**
     * @return array{public_slug: string, url: string}
     *
     * @throws InvalidArgumentException when the app is not a landing
     */
    public function publish(App $app): array
    {
        if ($app->kind !== AppKind::Landing) {
            throw new InvalidArgumentException(
                "Only landings can be published — '{$app->slug}' is a {$app->kind->value}. "
                .'Set settings.surface="landing" first.',
            );
        }

        // Keep an already-published slug stable (republish = no-op on identity);
        // otherwise mint a globally-unique one from the app's own slug.
        $publicSlug = $app->public_slug ?? $this->mintPublicSlug($app);

        $app->forceFill([
            'public_slug' => $publicSlug,
            'published_at' => $app->published_at ?? now(),
        ])->save();

        return [
            'public_slug' => $publicSlug,
            'url' => route('landing.public', ['public_slug' => $publicSlug]),
        ];
    }

    public function unpublish(App $app): void
    {
        $app->forceFill(['public_slug' => null, 'published_at' => null])->save();
    }

    /**
     * App slugs are only unique per-org; the public namespace is global. The
     * public slug is kebab-case (app slugs use underscores, but a public URL
     * reads /l/cafe-nebula, not /l/cafe_nebula). Use the kebabed app slug when
     * free, else suffix a counter (yoga-studio, yoga-studio-2…). Already-
     * published apps keep their existing slug whatever its shape.
     */
    private function mintPublicSlug(App $app): string
    {
        $base = Str::slug(str_replace('_', '-', $app->slug));
        if ($base === '') {
            $base = 'landing';
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
