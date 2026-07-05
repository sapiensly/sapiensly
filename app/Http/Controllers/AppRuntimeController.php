<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\BlockVisibilityFilter;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\BlockDataResolver;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Css\ScopedAppCss;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runtime endpoint for a tenant App. Resolves the active manifest, picks the
 * requested page (or the first one), and pre-resolves the data each block
 * needs so the client renders deterministically with one round-trip.
 *
 * Routes:
 *   GET /r/{app_slug}              → first page of the App
 *   GET /r/{app_slug}/{page_slug}  → specific page by slug
 */
class AppRuntimeController extends Controller
{
    public function __construct(
        private AppManifestService $manifestService,
        private BlockDataResolver $blockData,
        private AppAccessResolver $accessResolver,
        private BlockVisibilityFilter $visibility,
    ) {}

    public function __invoke(Request $request, string $appSlug, ?string $pageSlug = null): Response
    {
        $user = $request->user();

        $app = App::query()
            ->forAccountContext($user)
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$appSlug}' has no published manifest.");
        }

        $pages = $manifest['pages'] ?? [];
        if ($pages === []) {
            throw new NotFoundHttpException("App '{$appSlug}' has no pages yet.");
        }

        // Resolve the user's app-role capabilities once; every gate below reads it.
        $access = $this->accessResolver->resolve($app, $manifest, $user);
        if (! $access->hasAccess) {
            abort(403, 'You do not have access to this app.');
        }

        // Pages the role may view drive both the default landing page and the
        // navigation list sent to the client (a hidden page never appears).
        $viewablePages = array_values(array_filter(
            $pages,
            fn (array $p): bool => $access->canViewPage($p['id']),
        ));
        if ($viewablePages === []) {
            abort(403, 'You do not have access to any page in this app.');
        }

        $page = $pageSlug === null
            ? $viewablePages[0]
            : $this->findPageBySlug($pages, $pageSlug);

        if ($page === null) {
            throw new NotFoundHttpException("Page '{$pageSlug}' not found in app '{$appSlug}'.");
        }

        if (! $access->canViewPage($page['id'])) {
            abort(403, "You do not have access to page '{$pageSlug}'.");
        }

        // URL query params drive page-level filters: a block's data_source.filter
        // can read {{params.<name>}} and a filter_bar block writes them. Keep only
        // the string/array query values — they're safely bound in SQL downstream.
        $params = array_filter(
            $request->query(),
            fn ($v) => is_string($v) || is_array($v),
        );

        $context = [
            'current_user' => ['id' => $user->id, 'email' => $user->email],
            'params' => $params,
            '__access' => $access,
            // The viewer resolves a connected object's live read — a per-user
            // OAuth MCP source (e.g. YuhuGo) reads with THIS user's token.
            '__actor' => $user,
        ];

        // Drop blocks whose visibility rule excludes them BEFORE resolving their
        // data — a hidden block's data must never reach the wire. Gated on the
        // role AND, when set, the `expression` evaluated against this context
        // (e.g. show the cart only when {{params.order}} is set).
        $page['blocks'] = $this->visibility->visibleBlocks($page['blocks'] ?? [], $access, $context);

        $blockData = $this->blockData->resolve($app, $page['blocks'] ?? [], $manifest, $context);

        // Effective settings = manifest settings with the org Brandbook filling any
        // unset brand value (live fallback); the app's own choices win.
        $settings = $app->organization !== null
            ? $app->organization->brandbook()->applyToAppSettings($manifest['settings'] ?? [])
            : ($manifest['settings'] ?? []);

        // Derive a professional palette from the effective accent and ship it so
        // the surface exposes it as CSS vars (--sp-accent-50…900, chart series).
        $settings['palette'] = ColorPalette::fromAccent($settings['accent'] ?? OrganizationBrand::DEFAULT_ACCENT);

        return Inertia::render('runtime/Page', [
            'app' => [
                'id' => $app->id,
                'slug' => $app->slug,
                'name' => $app->name,
                'icon' => $app->icon,
                'color' => $app->color,
            ],
            'manifest' => [
                'navigation' => $manifest['navigation'] ?? null,
                'pages' => array_map(
                    fn (array $p) => ['id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'icon' => $p['icon'] ?? null],
                    $viewablePages,
                ),
                'settings' => $settings,
                // Objects ship to the client so block components can resolve
                // field_id → name/type for header labels and value formatting.
                'objects' => $manifest['objects'] ?? [],
                // Only the surface the runtime chat panel needs: whether to show
                // it and the assistant's display name. Instructions/capabilities
                // stay server-side (the toolset is derived there).
                'agent' => ($manifest['agent']['enabled'] ?? false) === true
                    ? ['enabled' => true, 'name' => $manifest['agent']['name'] ?? 'Assistant']
                    : null,
            ],
            'page' => $page,
            'blockData' => $blockData,
            // Current filter values, so a filter_bar renders pre-filled with the
            // active query (deep-link / back-button friendly).
            'params' => (object) $params,
            // Author CSS, compiled + scoped to the app surface (never leaks out).
            'customCss' => ScopedAppCss::compile($settings['custom_css'] ?? null),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    private function findPageBySlug(array $pages, string $slug): ?array
    {
        foreach ($pages as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }
}
