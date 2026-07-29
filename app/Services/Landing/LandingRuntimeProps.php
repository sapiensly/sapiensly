<?php

namespace App\Services\Landing;

use App\Models\App;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Css\ScopedAppCss;
use App\Support\Landing\LandingLanguages;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the Inertia props for the `runtime/Page` view of a LANDING, shared by
 * the two surfaces that must render byte-for-byte the same page: the public,
 * anonymous page (PublicLandingController) and the signed headless-render route
 * (LandingRenderController, whose screenshot feeds the design gate + the
 * render_landing MCP tool). Keeping one builder is what guarantees "the shot the
 * director judges is exactly what ships".
 *
 * Presentational only: blocks pass the PublicLandingBlocks allowlist (no tenant
 * data, visibility-ruled blocks dropped, action sequences stripped), so a
 * landing is complete on first paint with an empty blockData map.
 */
class LandingRuntimeProps
{
    /**
     * @param  array<string, mixed>|null  $manifest  the version's manifest to render
     * @param  string  $language  the negotiated language, '' for a single-language landing
     * @return array<string, mixed>
     */
    public static function build(App $app, ?array $manifest, bool $publicSurface, string $language = ''): array
    {
        $pages = $manifest['pages'] ?? [];
        if ($manifest === null || $pages === []) {
            throw new NotFoundHttpException;
        }

        // A landing is its first page (anchors cover in-page navigation).
        $page = $pages[0];
        $page['blocks'] = PublicLandingBlocks::filter($page['blocks'] ?? []);
        // Resolve the language INTO `content` and drop the variants, so the
        // browser is sent one language rather than every language it isn't
        // going to read.
        $page['blocks'] = LandingLanguages::apply($page['blocks'], $language);

        // Effective settings = manifest settings + org Brandbook fallback + the
        // derived palette, exactly like the authenticated runtime.
        $settings = $app->organization !== null
            ? $app->organization->brandbook()->applyToAppSettings($manifest['settings'] ?? [])
            : ($manifest['settings'] ?? []);
        $settings['palette'] = ColorPalette::fromAccent(
            $settings['accent'] ?? OrganizationBrand::DEFAULT_ACCENT,
            (string) ($settings['palette_mode'] ?? 'brand'),
        );

        $seo = is_array($settings['seo'] ?? null) ? $settings['seo'] : [];
        // The title and description are read by a person and by a crawler, so
        // they translate too — a Spanish page with an English <title> is half a
        // translation.
        $seoI18n = is_array($settings['seo_i18n'][$language] ?? null) ? $settings['seo_i18n'][$language] : [];
        $seo = array_merge($seo, array_filter($seoI18n, static fn ($v): bool => is_string($v) && $v !== ''));
        unset($settings['seo_i18n']);

        return [
            'app' => [
                'id' => $app->id,
                'slug' => $app->public_slug ?? $app->slug,
                'name' => $app->name,
                'icon' => $app->icon,
                'color' => $app->color,
                'kind' => $app->kind,
            ],
            'manifest' => [
                'navigation' => null,
                'pages' => [[
                    'id' => $page['id'],
                    'slug' => $page['slug'],
                    'name' => $page['name'],
                    'icon' => $page['icon'] ?? null,
                    'nav' => true,
                ]],
                'settings' => $settings,
                'objects' => [],
                'agent' => null,
            ],
            'page' => $page,
            'activeSlug' => $page['slug'],
            'blockData' => (object) [],
            'params' => (object) [],
            'customCss' => ScopedAppCss::compile($settings['custom_css'] ?? null),
            'seo' => [
                'title' => (string) ($seo['title'] ?? $app->name),
                'description' => (string) ($seo['description'] ?? ($app->description ?? '')),
                'og_image' => (string) ($seo['og_image'] ?? ''),
            ],
            'publicSurface' => $publicSurface,
            'turnstileSiteKey' => config('services.turnstile.site_key'),
            // What the page is in, and what else it exists as. The head uses
            // these for `hreflang` + canonical, and the page for its own
            // language switch — which has to stay visible: detection is a good
            // guess about a person, never a fact about them.
            'language' => $language,
            'languages' => LandingLanguages::declared($settings),
        ];
    }
}
