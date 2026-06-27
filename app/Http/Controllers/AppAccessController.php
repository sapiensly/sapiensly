<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\AppRoleAssignmentService;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
