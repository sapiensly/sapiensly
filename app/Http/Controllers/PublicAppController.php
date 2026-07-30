<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\BlockVisibilityFilter;
use App\Services\Records\BlockDataResolver;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Css\ScopedAppCss;
use App\Support\Manifest\PageNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders a PUBLIC PORTAL for a visitor with no account — the unauthenticated
 * sibling of {@see AppRuntimeController}, serving the same runtime/Page
 * component so a portal gets every block the authenticated runtime has.
 * BindPublicAppContext already resolved the app, checked the portal is open and
 * bound the owner's tenant scope.
 *
 * What differs, and why:
 *  - The access context comes from AppAccessResolver::resolvePublic — STRICT,
 *    so a page or object with no explicit policy is invisible rather than open.
 *    There is no administrator bypass to reach and no default-role fallback.
 *  - There is no `current_user`. An expression that reads {{current_user.id}}
 *    resolves to null here, so a row_filter written against it excludes
 *    everything — which is the safe direction for a stranger.
 *  - No agent panel: the runtime agent is session-authenticated and bills per
 *    message, so an anonymous surface must not mount it.
 *  - Every refusal is a 404, matching the middleware. A 403 would confirm the
 *    portal exists and hint at what is behind it.
 */
class PublicAppController extends Controller
{
    public function __construct(
        private readonly BlockDataResolver $blockData,
        private readonly AppAccessResolver $accessResolver,
        private readonly BlockVisibilityFilter $visibility,
    ) {}

    public function __invoke(Request $request, string $publicSlug, ?string $pageSlug = null): Response
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');
        /** @var array<string, mixed> $manifest */
        $manifest = $request->attributes->get('publicAppManifest');

        $access = $this->accessResolver->resolvePublic($manifest);
        if (! $access->hasAccess) {
            abort(404);
        }

        $pages = $manifest['pages'] ?? [];
        $viewablePages = array_values(array_filter(
            $pages,
            fn (array $p): bool => $access->canViewPage($p['id']),
        ));
        if ($viewablePages === []) {
            abort(404);
        }

        $navigableViewable = array_values(array_filter($viewablePages, PageNavigation::isNavigable(...)));
        $page = $pageSlug === null
            ? ($navigableViewable[0] ?? $viewablePages[0])
            : $this->findViewablePage($viewablePages, $pageSlug);

        if ($page === null) {
            abort(404);
        }

        $activeSlug = PageNavigation::activeSlug($page, $pages);

        $params = array_filter(
            $request->query(),
            fn ($v) => is_string($v) || is_array($v),
        );

        $context = [
            'params' => $params,
            '__access' => $access,
            // No acting user: a connected source falls back to the
            // integration's own service credentials, never to a person's token.
            '__actor' => null,
        ];

        $page['blocks'] = $this->visibility->visibleBlocks($page['blocks'] ?? [], $access, $context);

        $settings = $app->organization !== null
            ? $app->organization->brandbook()->applyToAppSettings($manifest['settings'] ?? [])
            : ($manifest['settings'] ?? []);

        $settings['palette'] = ColorPalette::fromAccent(
            $settings['accent'] ?? OrganizationBrand::DEFAULT_ACCENT,
            (string) ($settings['palette_mode'] ?? 'brand'),
        );

        return Inertia::render('runtime/Page', [
            'app' => [
                'id' => $app->id,
                'slug' => $app->slug,
                'name' => $app->name,
                'icon' => $app->icon,
                'color' => $app->color,
                'kind' => $app->kind,
            ],
            'manifest' => [
                'navigation' => $manifest['navigation'] ?? null,
                'pages' => array_map(
                    fn (array $p) => [
                        'id' => $p['id'],
                        'slug' => $p['slug'],
                        'name' => $p['name'],
                        'icon' => $p['icon'] ?? null,
                        'nav' => PageNavigation::isNavigable($p),
                    ],
                    $viewablePages,
                ),
                'settings' => $settings,
                'objects' => $manifest['objects'] ?? [],
                'agent' => null,
            ],
            'page' => $page,
            'activeSlug' => $activeSlug,
            // Nav links and action POSTs resolve against the portal's own mount
            // instead of the authenticated /r/{slug}.
            'mount' => '/a/'.$app->public_slug,
            'blockData' => Inertia::defer(fn () => $this->blockData->resolve($app, $page['blocks'] ?? [], $manifest, $context)),
            'params' => (object) $params,
            'customCss' => ScopedAppCss::compile($settings['custom_css'] ?? null),
            'seo' => $settings['seo'] ?? null,
        ]);
    }

    /**
     * Look the page up among the VIEWABLE pages only. Searching all pages and
     * then checking the policy would let a 404-vs-403 difference reveal which
     * page slugs exist behind the portal.
     *
     * @param  list<array<string, mixed>>  $viewablePages
     * @return array<string, mixed>|null
     */
    private function findViewablePage(array $viewablePages, string $slug): ?array
    {
        foreach ($viewablePages as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }
}
