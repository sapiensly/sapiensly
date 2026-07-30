<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The in-app inbox for one app: what a workflow's `notify.send` step raised for
 * the signed-in person, and marking it read.
 *
 * Scoped twice over. RLS confines the query to the tenant, and the recipient
 * filter confines it to THIS user — a member of the same organization must not
 * read a notification addressed to a colleague, even though both rows live
 * inside the same tenant.
 */
class AppNotificationController extends Controller
{
    /** Newest first, bounded — the bell shows a list, not an archive. */
    private const LIMIT = 30;

    public function index(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolveApp($request, $appSlug);
        $user = $request->user();

        $notifications = AppNotification::query()
            ->inbox($app->id, $user->id)
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (AppNotification $n): array => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'link' => $n->link,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread' => AppNotification::query()
                ->inbox($app->id, $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /**
     * Mark one notification read, or all of them when no id is given. The update
     * is filtered by recipient, so an id belonging to a colleague matches
     * nothing rather than being marked on their behalf.
     */
    public function markRead(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolveApp($request, $appSlug);
        $user = $request->user();

        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
        ]);

        $query = AppNotification::query()
            ->where('app_id', $app->id)
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at');

        if (! empty($data['id'])) {
            $query->where('id', $data['id']);
        }

        $query->update(['read_at' => now()]);

        return response()->json([
            'unread' => AppNotification::query()
                ->inbox($app->id, $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    private function resolveApp(Request $request, string $appSlug): App
    {
        $app = App::query()
            ->forAccountContext($request->user())
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        return $app;
    }
}
