<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Http\Requests\App\StoreAppRequest;
use App\Http\Requests\App\UpdateAppRequest;
use App\Models\App;
use App\Models\Record;
use App\Services\Apps\AppPackage;
use App\Services\Apps\AppTemplateCatalog;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\AppNaming;
use Illuminate\Http\JsonResponse;
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
            // Starter packages, so a new app can begin from something real
            // instead of an empty page.
            'templates' => app(AppTemplateCatalog::class)->all(),
        ]);
    }

    public function store(StoreAppRequest $request): RedirectResponse
    {
        $user = $request->user();
        $visibility = $request->enum('visibility', Visibility::class) ?? Visibility::Private;

        // Apps now start unnamed and open straight into the Builder — the first
        // prompt names them (see AppBuilderController::nameAppFromFirstPrompt),
        // like a chat titling itself. A caller may still pass a name; the slug is
        // always auto-derived unique.
        $name = trim((string) $request->string('name')) ?: AppNaming::UNTITLED;
        $slug = AppNaming::uniqueSlug(trim((string) $request->string('slug')) ?: $name, $user->organization_id);

        $app = App::create([
            'user_id' => $user->id,
            // Scope the app to the owner's tenant (their org, or null in personal
            // context) REGARDLESS of visibility — `visibility` alone decides
            // private-vs-organization sharing WITHIN that tenant.
            'organization_id' => $user->organization_id,
            'slug' => $slug,
            'name' => $name,
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
            ->route('apps.builder', $app)
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

        // The slug is fixed (it's the runtime URL); UpdateAppRequest doesn't
        // accept it. Name/description edits are mirrored onto the active
        // manifest so it never drifts from the App model.
        $app->update($request->validated());
        $this->manifestService->syncManifestIdentity($app->refresh());

        return redirect()->route('apps.show', $app)->with('success', 'App updated.');
    }

    public function destroy(Request $request, App $app): RedirectResponse
    {
        $this->authorizeAccess($request, $app);

        $app->delete();

        return redirect()->route('apps.index')->with('success', 'App deleted.');
    }

    /**
     * Delete a brand-new app ONLY if it's still pristine — the placeholder name
     * (never prompted, so never named) and just its empty initial version. Called
     * when the user backs out of the Builder without building anything, so the
     * grid isn't littered with empty "Nueva app" entries. Server-authoritative:
     * once the app has any content it's a no-op, so a stray call can't delete a
     * real app.
     */
    public function discardEmpty(Request $request, App $app): RedirectResponse
    {
        $this->authorizeAccess($request, $app);

        $pristine = $app->name === AppNaming::UNTITLED
            && $app->versions()->count() <= 1;
        if ($pristine) {
            $app->delete();
        }

        return redirect()->route('apps.index');
    }

    /**
     * Download the app as a portable package. What cannot travel — connected
     * sources, workflows touching an integration or agent, a chatbot binding —
     * is stripped and listed inside the file under `portability.removed`, so
     * whoever installs it reads what needs re-wiring before wondering why.
     */
    public function export(Request $request, App $app): JsonResponse
    {
        $this->authorizeAccess($request, $app);

        try {
            $package = app(AppPackage::class)->export($app);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($package)->withHeaders([
            'Content-Disposition' => 'attachment; filename="'.$app->slug.'.sapiensly.json"',
        ]);
    }

    /** Install an uploaded package as a new app. */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'package' => ['required', 'file', 'mimes:json,txt', 'max:4096'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $decoded = json_decode((string) file_get_contents($data['package']->getRealPath()), true);
        if (! is_array($decoded)) {
            return back()->withErrors(['package' => 'That file is not valid JSON.']);
        }

        try {
            $result = app(AppPackage::class)->import($decoded, $request->user(), $data['name'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()
            ->route('apps.builder', $result['app'])
            ->with('import_notes', $result['notes']);
    }

    /** Copy an app in place — the same path an installed package takes. */
    public function duplicate(Request $request, App $app): RedirectResponse
    {
        $this->authorizeAccess($request, $app);

        try {
            $result = app(AppPackage::class)->duplicate($app, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['app' => $e->getMessage()]);
        }

        return redirect()
            ->route('apps.builder', $result['app'])
            ->with('import_notes', $result['notes']);
    }

    /** Install one of the built-in starter templates. */
    public function createFromTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template' => ['required', 'string', 'max:60'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $package = app(AppTemplateCatalog::class)->package($data['template']);
        if ($package === null) {
            return back()->withErrors(['template' => 'No such template.']);
        }

        $result = app(AppPackage::class)->import($package, $request->user(), $data['name'] ?? null);

        return redirect()
            ->route('apps.builder', $result['app'])
            ->with('import_notes', $result['notes']);
    }

    private function authorizeAccess(Request $request, App $app): void
    {
        abort_unless($app->isVisibleTo($request->user()), 403);
    }
}
