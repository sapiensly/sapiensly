<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\AppRoleAssignmentService;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manages who can use a built app and in which role (Phase 4 of the app access
 * layer). The roster + assignment endpoints back the builder's "Access" panel.
 *
 * Every action is gated on the SAME admin set the runtime resolver bypasses
 * (sysadmin / org owner / app owner): only someone who fully controls the app
 * may hand out roles within it.
 */
class AppAccessController extends Controller
{
    public function __construct(
        private AppManifestService $manifestService,
        private AppAccessResolver $accessResolver,
        private AppRoleAssignmentService $assignments,
    ) {}

    public function index(Request $request, App $app): JsonResponse
    {
        $manifest = $this->assertCanManage($request, $app);

        return response()->json($this->roster($app, $manifest));
    }

    public function store(Request $request, App $app): JsonResponse
    {
        $manifest = $this->assertCanManage($request, $app);

        $data = $request->validate([
            'assigned_user_id' => ['required', 'integer'],
            'role_slug' => ['required', 'string'],
        ]);

        $this->assignments->assign(
            $app,
            $manifest,
            $request->user(),
            (int) $data['assigned_user_id'],
            $data['role_slug'],
        );

        return response()->json($this->roster($app, $manifest));
    }

    public function destroy(Request $request, App $app, string $assignment): JsonResponse
    {
        $manifest = $this->assertCanManage($request, $app);

        $this->assignments->revoke($app, $assignment);

        return response()->json($this->roster($app, $manifest));
    }

    /**
     * Switch the app's access_mode (open ↔ allowlist). It lives in the manifest,
     * so the change is an RFC 6902 patch that creates a new reversible version.
     */
    public function updateMode(Request $request, App $app): JsonResponse
    {
        $this->assertCanManage($request, $app);

        $data = $request->validate([
            'access_mode' => ['required', 'string', Rule::in(['open', 'allowlist'])],
        ]);

        // `permissions` is a required top-level object, so `add` on its member
        // replaces the mode when present and adds it otherwise.
        $ops = [['op' => 'add', 'path' => '/permissions/access_mode', 'value' => $data['access_mode']]];

        try {
            $this->manifestService->applyPatch($app, $ops, $request->user(), "Access mode set to {$data['access_mode']} from the builder.");
        } catch (InvalidManifestException $e) {
            return response()->json([
                'error' => 'invalid_manifest',
                'message' => 'The access-mode change did not pass validation.',
                'errors' => $e->result->errorsArray(),
            ], 422);
        }

        // applyPatch points current_version_id at the new version on a freshly
        // locked model, so reload $app before reading back the active manifest.
        $manifest = $this->manifestService->getActiveManifest($app->refresh());

        return response()->json($this->roster($app, $manifest ?? []));
    }

    /**
     * Set what this app may leave on a device (`settings.offline`).
     *
     * It lives beside the access mode because it is the same kind of statement:
     * the mode says who may open the app, this says which of its data may sit
     * on a phone's disk after they close it. An owner who thinks "who can see
     * the salaries?" is already on this screen.
     */
    public function updateOffline(Request $request, App $app): JsonResponse
    {
        $manifest = $this->assertCanManage($request, $app);

        $slugs = collect($manifest['objects'] ?? [])->pluck('slug')->filter()->values();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'exclude_objects' => ['array', 'max:50'],
            // Checked against the app's OWN objects: an exclusion naming
            // something that does not exist protects nothing and would sit in
            // the manifest looking as though it did.
            'exclude_objects.*' => ['string', Rule::in($slugs)],
        ]);

        $enabled = (bool) $data['enabled'];
        $exclude = array_values(array_unique($data['exclude_objects'] ?? []));

        // The default is offline ON with nothing excluded, and absent MEANS the
        // default. Storing a no-op would make every app's manifest carry a
        // setting nobody chose, and "unset" would stop being readable as "we
        // never asked".
        $isDefault = $enabled && $exclude === [];

        $ops = $this->offlineOps($manifest, $isDefault, $enabled, $exclude);

        // Setting the default on an app that never said otherwise changes
        // nothing, and a version whose diff is empty is noise in a history
        // people read to find out what happened.
        if ($ops === []) {
            return response()->json($this->roster($app, $manifest));
        }

        try {
            $this->manifestService->applyPatch($app, $ops, $request->user(), 'Offline policy changed from the builder.');
        } catch (InvalidManifestException $e) {
            return response()->json([
                'error' => 'invalid_manifest',
                'message' => 'The offline change did not pass validation.',
                'errors' => $e->result->errorsArray(),
            ], 422);
        }

        $manifest = $this->manifestService->getActiveManifest($app->refresh());

        return response()->json($this->roster($app, $manifest ?? []));
    }

    /**
     * The patch that writes — or clears — the offline block.
     *
     * `settings` is optional on a manifest, so adding a member of it fails on an
     * app that never had one. And removing a path that is not there fails too,
     * which is why clearing is a no-op when there is nothing to clear.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $exclude
     * @return list<array<string, mixed>>
     */
    private function offlineOps(array $manifest, bool $isDefault, bool $enabled, array $exclude): array
    {
        if ($isDefault) {
            if (! isset($manifest['settings']['offline'])) {
                return [];
            }

            // Removing the LAST key of `settings` would leave an empty PHP
            // array, which encodes as a JSON array and fails the schema — so
            // when this was the only setting, the object goes with it.
            return array_keys($manifest['settings']) === ['offline']
                ? [['op' => 'remove', 'path' => '/settings']]
                : [['op' => 'remove', 'path' => '/settings/offline']];
        }

        $value = ['enabled' => $enabled];
        if ($exclude !== []) {
            $value['exclude_objects'] = $exclude;
        }

        return isset($manifest['settings'])
            ? [['op' => 'add', 'path' => '/settings/offline', 'value' => $value]]
            : [['op' => 'add', 'path' => '/settings', 'value' => ['offline' => $value]]];
    }

    /**
     * The roster, plus what this screen needs beyond roles.
     *
     * The offline block rides along because both halves of this panel are saved
     * by the same three endpoints and re-read from whatever they return — a
     * second request to fetch it would be one more thing to keep in step.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function roster(App $app, array $manifest): array
    {
        $offline = $manifest['settings']['offline'] ?? [];

        return [
            ...$this->assignments->roster($app, $manifest),
            'objects' => collect($manifest['objects'] ?? [])
                ->map(fn (array $o): array => ['slug' => $o['slug'] ?? '', 'name' => $o['name'] ?? ($o['slug'] ?? '')])
                ->filter(fn (array $o): bool => $o['slug'] !== '')
                ->values()
                ->all(),
            'offline' => [
                'enabled' => ($offline['enabled'] ?? true) !== false,
                'exclude_objects' => array_values((array) ($offline['exclude_objects'] ?? [])),
            ],
        ];
    }

    /**
     * Confirm the requester administers the app (the resolver's bypass set) and
     * return its active manifest. 403 otherwise, 404 if it has no manifest.
     *
     * @return array<string, mixed>
     */
    private function assertCanManage(Request $request, App $app): array
    {
        abort_unless($app->isVisibleTo($request->user()), 403);

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$app->slug}' has no published manifest.");
        }

        $access = $this->accessResolver->resolve($app, $manifest, $request->user());
        abort_unless($access->bypass, 403, 'Only an app or organization administrator can manage access.');

        return $manifest;
    }
}
