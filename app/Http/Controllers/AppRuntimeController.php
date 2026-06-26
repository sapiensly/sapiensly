<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\BlockDataResolver;
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

        $page = $pageSlug === null
            ? $pages[0]
            : $this->findPageBySlug($pages, $pageSlug);

        if ($page === null) {
            throw new NotFoundHttpException("Page '{$pageSlug}' not found in app '{$appSlug}'.");
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
        ];

        $blockData = $this->blockData->resolve($app, $page['blocks'] ?? [], $manifest, $context);

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
                    $pages,
                ),
                'settings' => $manifest['settings'] ?? [],
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
