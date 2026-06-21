<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Http\Requests\App\StoreAppRequest;
use App\Http\Requests\App\UpdateAppRequest;
use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function __construct(
        private AppManifestService $manifestService,
    ) {}

    public function index(Request $request): Response
    {
        $apps = App::query()
            ->forAccountContext($request->user())
            ->with('currentVersion:id,version_number,created_at')
            ->latest()
            ->paginate(20);

        return Inertia::render('apps/Index', [
            'apps' => $apps,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('apps/Create');
    }

    public function store(StoreAppRequest $request): RedirectResponse
    {
        $user = $request->user();
        $visibility = $request->enum('visibility', Visibility::class) ?? Visibility::Private;

        $app = App::create([
            'user_id' => $user->id,
            // Scope the app to the owner's tenant (their org, or null in personal
            // context) REGARDLESS of visibility — `visibility` alone decides
            // private-vs-organization sharing WITHIN that tenant. Nulling the org
            // for a private app hid it from its own org-context owner, because
            // isVisibleTo / forAccountContext filter by organization_id (a 403 on
            // the post-create redirect to show).
            'organization_id' => $user->organization_id,
            'slug' => $request->string('slug')->toString(),
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'icon' => $request->input('icon'),
            'color' => $request->input('color'),
            'visibility' => $visibility,
        ]);

        $this->manifestService->createVersion(
            $app,
            $this->manifestService->initialManifest($app),
            $user,
            'Initial version',
        );

        return redirect()
            ->route('apps.show', $app)
            ->with('success', 'App created.');
    }

    public function show(Request $request, App $app): Response
    {
        $this->authorizeAccess($request, $app);

        $versions = $app->versions()
            ->select(['id', 'app_id', 'version_number', 'change_summary', 'created_by_user_id', 'created_at'])
            ->with('createdBy:id,name')
            ->limit(50)
            ->get();

        $manifest = $this->manifestService->getActiveManifest($app);

        return Inertia::render('apps/Show', [
            'app' => $app->only(['id', 'slug', 'name', 'description', 'icon', 'color', 'visibility', 'current_version_id', 'created_at']),
            'manifest' => $manifest,
            'overview' => $this->buildOverview($app, $manifest),
            'versions' => $versions,
        ]);
    }

    /**
     * Assemble the App detail "overview": the pieces a user actually wants
     * when they land on the page — pages they can open, the data model with
     * live record counts, and the automations wired up. The raw manifest and
     * version history remain available but are secondary to this digest.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array{
     *     stats: array{pages: int, objects: int, records: int, workflows: int},
     *     pages: list<array<string, mixed>>,
     *     objects: list<array<string, mixed>>,
     *     workflows: list<array<string, mixed>>,
     *     settings: array<string, mixed>
     * }|null
     */
    private function buildOverview(App $app, ?array $manifest): ?array
    {
        if ($manifest === null) {
            return null;
        }

        $objects = $manifest['objects'] ?? [];
        $pages = $manifest['pages'] ?? [];
        $workflows = $manifest['workflows'] ?? [];

        // One grouped count for every object — avoids N COUNT(*) round-trips.
        $counts = Record::query()
            ->where('app_id', $app->id)
            ->selectRaw('object_definition_id, count(*) as c')
            ->groupBy('object_definition_id')
            ->pluck('c', 'object_definition_id')
            ->map(fn ($c) => (int) $c)
            ->all();

        $objectNamesById = [];
        foreach ($objects as $object) {
            $objectNamesById[$object['id']] = $object['name'] ?? $object['slug'] ?? $object['id'];
        }

        return [
            'stats' => [
                'pages' => count($pages),
                'objects' => count($objects),
                'records' => array_sum($counts),
                'workflows' => count($workflows),
            ],
            'pages' => array_map(fn (array $p) => [
                'id' => $p['id'],
                'slug' => $p['slug'],
                'name' => $p['name'],
                'icon' => $p['icon'] ?? null,
                'block_count' => count($p['blocks'] ?? []),
            ], $pages),
            'objects' => array_map(fn (array $o) => [
                'id' => $o['id'],
                'slug' => $o['slug'],
                'name' => $o['name'] ?? $o['slug'],
                'field_count' => count($o['fields'] ?? []),
                'record_count' => $counts[$o['id']] ?? 0,
            ], $objects),
            'workflows' => array_map(fn (array $w) => [
                'id' => $w['id'],
                'name' => $w['name'] ?? $w['slug'] ?? $w['id'],
                'trigger_type' => $w['trigger']['type'] ?? null,
                'object_name' => isset($w['trigger']['object_id'])
                    ? ($objectNamesById[$w['trigger']['object_id']] ?? null)
                    : null,
            ], $workflows),
            'settings' => [
                'default_locale' => $manifest['settings']['default_locale'] ?? null,
                'default_currency' => $manifest['settings']['default_currency'] ?? null,
                'default_timezone' => $manifest['settings']['default_timezone'] ?? null,
            ],
        ];
    }

    public function update(UpdateAppRequest $request, App $app): RedirectResponse
    {
        $this->authorizeAccess($request, $app);

        $app->update($request->validated());

        return redirect()->route('apps.show', $app)->with('success', 'App updated.');
    }

    public function destroy(Request $request, App $app): RedirectResponse
    {
        $this->authorizeAccess($request, $app);

        $app->delete();

        return redirect()->route('apps.index')->with('success', 'App deleted.');
    }

    private function authorizeAccess(Request $request, App $app): void
    {
        abort_unless($app->isVisibleTo($request->user()), 403);
    }
}
