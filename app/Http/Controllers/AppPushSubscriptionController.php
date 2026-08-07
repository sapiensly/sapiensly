<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\PushSubscription;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Notifications\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a browser says "you may tell me things", and takes it back.
 *
 * Per APP rather than per account: somebody who allowed notifications for the
 * field-service app has not agreed to hear from every app the organization
 * builds afterwards, and the notification they do get should open the app the
 * work is in.
 *
 * Access is checked on the way in, not just on the way out. Without it, anyone
 * who could name an app slug could register a device against it and receive
 * whatever that app's workflows say — which is a subscription to somebody
 * else's business.
 */
class AppPushSubscriptionController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
        private readonly AppAccessResolver $accessResolver,
        private readonly PushSender $push,
    ) {}

    /** What the client needs before it can subscribe at all. */
    public function key(Request $request, string $appSlug): JsonResponse
    {
        // Resolved for the access check rather than for the key: the public
        // half is meant to be handed out, but there is no reason a stranger
        // should learn which apps exist by asking.
        $this->resolve($request, $appSlug);

        return response()->json([
            'configured' => $this->push->isConfigured(),
            // The public half, which is exactly what it is for: the browser
            // binds its subscription to this key and the push service checks
            // our signature against it.
            'key' => $this->push->isConfigured() ? (string) config('push.vapid.public') : null,
        ]);
    }

    public function store(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolve($request, $appSlug);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);

        $endpoint = $data['endpoint'];

        // A browser re-registers on every visit, and hands back the same
        // endpoint with a fresh key pair after a worker update — so the
        // endpoint is the identity and the keys are what gets replaced. Without
        // this a phone accumulates a row per visit and every notification is
        // sent to it a dozen times.
        PushSubscription::updateOrCreate(
            [
                'app_id' => $app->id,
                'endpoint_hash' => PushSubscription::hashFor($endpoint),
            ],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $endpoint,
                'p256dh' => $request->input('keys.p256dh'),
                'auth' => $request->input('keys.auth'),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolve($request, $appSlug);

        $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::query()
            ->where('app_id', $app->id)
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashFor($request->string('endpoint')->toString()))
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function resolve(Request $request, string $appSlug): App
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

        if (! $this->accessResolver->resolve($app, $manifest, $user)->hasAccess) {
            abort(403, 'You do not have access to this app.');
        }

        return $app;
    }
}
