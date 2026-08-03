<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\AppVersion;
use App\Services\Apps\AppActivityFeed;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An app's history, and the way back.
 *
 * Every change to an app has always been saved as a new version, and rolling
 * back has always been possible — over MCP. From the builder there was no
 * route, no button and no list: the chat could restructure your app in one turn
 * and the only recovery was to describe the old shape and hope. A history
 * nobody can reach is a backup nobody has.
 *
 * Restoring is append-only, like `rollback_app`: it writes a NEW version
 * carrying the old manifest, so the thing you undid is still there to redo.
 */
class AppVersionsController extends Controller
{
    /** How far back the panel lists. Past this, the answer is not a list. */
    private const LIMIT = 50;

    public function __construct(private readonly AppManifestService $manifests) {}

    public function index(Request $request, App $app): JsonResponse
    {
        $this->assertCanEdit($request, $app);

        $versions = AppVersion::query()
            ->where('app_id', $app->id)
            ->with('createdBy:id,name')
            ->orderByDesc('version_number')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'current_version_id' => $app->current_version_id,
            'versions' => $versions->map(fn (AppVersion $v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'change_summary' => $v->change_summary,
                'author' => $v->createdBy?->name,
                'created_at' => $v->created_at?->toIso8601String(),
                'is_current' => $v->id === $app->current_version_id,
                // What a version actually holds, so the list says something
                // about each entry beyond when it happened.
                'objects' => count($v->manifest['objects'] ?? []),
                'pages' => count($v->manifest['pages'] ?? []),
            ])->all(),
        ]);
    }

    /**
     * Everything that has happened to this app, from every source that already
     * records it — see {@see AppActivityFeed} for why nothing is copied.
     */
    public function activity(Request $request, App $app): JsonResponse
    {
        $this->assertCanEdit($request, $app);

        return response()->json(['entries' => app(AppActivityFeed::class)->for($app)]);
    }

    public function restore(Request $request, App $app, string $version): JsonResponse
    {
        $this->assertCanEdit($request, $app);

        $target = AppVersion::query()
            ->where('app_id', $app->id)
            ->where('id', $version)
            ->first();

        if ($target === null) {
            throw new HttpException(404, 'That version does not belong to this app.');
        }

        if ($target->id === $app->current_version_id) {
            throw new HttpException(422, 'That version is already the live one.');
        }

        $new = $this->manifests->rollbackTo($app, $target, $request->user());

        return response()->json([
            'restored_from' => $target->version_number,
            'version_number' => $new->version_number,
        ]);
    }

    /**
     * Reading the history is reading the app; restoring rewrites it. Both are
     * gated on being able to CHANGE the app rather than merely see it — the
     * builder is where this lives and the builder is not a viewer's screen.
     */
    private function assertCanEdit(Request $request, App $app): void
    {
        abort_unless($app->isVisibleTo($request->user()), 403);
    }
}
