<?php

namespace App\Services\Apps;

use App\Enums\AppKind;
use App\Models\App;
use App\Services\Landing\LandingPublisher;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\PublicSlug;
use InvalidArgumentException;

/**
 * The single publish/unpublish path for a PUBLIC PORTAL — a regular app opened
 * to people with no account, at /a/{public_slug}. The landing sibling of this
 * class is {@see LandingPublisher}; both mint from the
 * same global slug namespace.
 *
 * Publishing is deliberately a SECOND act, separate from setting
 * permissions.public.enabled in the manifest. Authoring who may see what and
 * deciding the app is now on the internet are different decisions, and a
 * builder turn that flips a manifest flag must not be able to put tenant data
 * online as a side effect.
 */
class PortalPublisher
{
    public function __construct(
        private readonly AppManifestService $manifestService,
    ) {}

    /**
     * @return array{public_slug: string, url: string, role: string, writes: bool}
     *
     * @throws InvalidArgumentException when the app is not a portal-shaped app,
     *                                  or its manifest has not opened the portal
     */
    public function publish(App $app): array
    {
        if ($app->kind === AppKind::Landing) {
            throw new InvalidArgumentException(
                "'{$app->slug}' is a landing — publish it with the landing publisher; it goes online at /l/{slug}.",
            );
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null) {
            throw new InvalidArgumentException("'{$app->slug}' has no published manifest version to serve.");
        }

        $public = $manifest['permissions']['public'] ?? null;
        if (! is_array($public) || ($public['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException(
                "'{$app->slug}' has no public portal configured. Set permissions.public = {enabled: true, role_id, allow_writes} and grant that role the pages and objects visitors may reach — a portal shows a visitor ONLY what is granted explicitly.",
            );
        }

        // The gate the URL will enforce on every request, checked once here so
        // publishing fails loudly instead of minting a slug that always 403s.
        $access = app(AppAccessResolver::class)->resolvePublic($manifest);
        if (! $access->hasAccess) {
            throw new InvalidArgumentException(
                "'{$app->slug}' declares a public portal whose role_id does not resolve to a usable visitor role.",
            );
        }

        $publicSlug = PublicSlug::mint($app, 'portal');

        $app->forceFill([
            'public_slug' => $publicSlug,
            'published_at' => $app->published_at ?? now(),
        ])->save();

        return [
            'public_slug' => $publicSlug,
            'url' => route('portal.public', ['public_slug' => $publicSlug]),
            'role' => $access->roleSlugs[0] ?? '',
            'writes' => ($public['allow_writes'] ?? false) === true,
        ];
    }

    /**
     * Takes the portal offline. The slug is released with it, so a later
     * republish may mint a different one — the same contract as a landing.
     */
    public function unpublish(App $app): void
    {
        $app->forceFill(['public_slug' => null, 'published_at' => null])->save();
    }
}
