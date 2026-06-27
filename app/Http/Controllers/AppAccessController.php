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

        return response()->json($this->assignments->roster($app, $manifest));
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

        return response()->json($this->assignments->roster($app, $manifest));
    }

    public function destroy(Request $request, App $app, string $assignment): JsonResponse
    {
        $manifest = $this->assertCanManage($request, $app);

        $this->assignments->revoke($app, $assignment);

        return response()->json($this->assignments->roster($app, $manifest));
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

        return response()->json($this->assignments->roster($app, $manifest ?? []));
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
