<?php

namespace App\Services\Landing;

use App\Enums\AppKind;
use App\Models\App;
use App\Support\Apps\PublicSlug;
use InvalidArgumentException;

/**
 * The single publish/unpublish path for landings — shared by the MCP
 * publish_landing tool and the builder UI so both surfaces enforce the same
 * gate: only a landing can go public, the public slug is GLOBALLY unique
 * (app slugs are only per-org), and unpublishing makes the public URL 404.
 */
class LandingPublisher
{
    public function __construct(
        private readonly ChatbotLandingOrigins $origins,
    ) {}

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
        $publicSlug = PublicSlug::mint($app, 'landing');

        $app->forceFill([
            'public_slug' => $publicSlug,
            'published_at' => $app->published_at ?? now(),
        ])->save();

        // Publishing is what makes this page's origin legitimate for the chatbot
        // it carries; drop the derived-origins memo so the widget accepts it now
        // rather than up to a minute from now.
        $this->origins->forget($app->chatbot_id);

        return [
            'public_slug' => $publicSlug,
            'url' => route('landing.public', ['public_slug' => $publicSlug]),
        ];
    }

    public function unpublish(App $app): void
    {
        $app->forceFill(['public_slug' => null, 'published_at' => null])->save();

        // …and taking it down withdraws that origin immediately.
        $this->origins->forget($app->chatbot_id);
    }
}
