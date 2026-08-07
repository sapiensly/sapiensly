<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\TrackingSession;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Tracking\TrackingService;
use App\Support\Tracking\TrackingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starting, feeding and stopping a trail.
 *
 * Two rails run through every method. The POLICY is checked on the server on
 * every call, not just when the bar decides to show itself — a client that
 * posts pings to an app whose owner never turned tracking on must be refused,
 * or the setting is decoration. And a session belongs to the person who
 * started it: nobody can feed or stop somebody else's, which is what keeps a
 * trail something a person is producing rather than something being done to
 * them.
 */
class AppTrackingController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
        private readonly AppAccessResolver $accessResolver,
        private readonly TrackingService $tracking,
    ) {}

    public function start(Request $request, string $appSlug): JsonResponse
    {
        [$app, $manifest, $policy] = $this->resolve($request, $appSlug);

        $request->validate(['record_id' => ['nullable', 'string', 'max:120']]);

        $session = $this->tracking->start(
            $app,
            $manifest,
            $request->user(),
            $policy,
            $request->string('record_id')->toString() ?: null,
        );

        return response()->json([
            'session_id' => $session->id,
            'has_geofence' => $session->target_lat !== null,
            'limits' => [
                'min_interval_s' => $policy->minIntervalSeconds,
                'min_distance_m' => $policy->minDistanceMeters,
            ],
        ]);
    }

    public function pings(Request $request, string $appSlug): JsonResponse
    {
        [$app, $manifest] = $this->resolve($request, $appSlug);

        $request->validate([
            'session_id' => ['required', 'string', 'max:120'],
            // Batched, and bounded: a client that has been offline for an hour
            // should send what it has, not everything it ever had.
            'pings' => ['required', 'array', 'max:200'],
            'pings.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'pings.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy' => ['nullable', 'numeric'],
            'pings.*.at' => ['nullable', 'string', 'max:40'],
            'pings.*.gap' => ['nullable', 'boolean'],
        ]);

        $session = $this->sessionFor($request, $app);

        // A stopped session takes nothing more. Somebody who pressed stop has
        // said the thing they were asked to consent to is over, and a client
        // that keeps posting must not be able to override that.
        if (! $session->isLive()) {
            return response()->json(['stopped' => true], 409);
        }

        return response()->json($this->tracking->record(
            $app,
            $manifest,
            $session,
            $request->input('pings', []),
            $request->user(),
        ));
    }

    public function stop(Request $request, string $appSlug): JsonResponse
    {
        [$app] = $this->resolve($request, $appSlug);

        $request->validate(['session_id' => ['required', 'string', 'max:120']]);

        $this->tracking->stop($this->sessionFor($request, $app));

        return response()->json(['ok' => true]);
    }

    private function sessionFor(Request $request, App $app): TrackingSession
    {
        $session = TrackingSession::query()
            ->where('app_id', $app->id)
            ->where('user_id', $request->user()->id)
            ->where('id', $request->string('session_id')->toString())
            ->first();

        if ($session === null) {
            throw new NotFoundHttpException('No such tracking session.');
        }

        return $session;
    }

    /** @return array{0: App, 1: array<string, mixed>, 2: TrackingPolicy} */
    private function resolve(Request $request, string $appSlug): array
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

        $policy = TrackingPolicy::fromManifest($manifest);
        if (! $policy->enabled) {
            // Not 404: the app is there and the person can open it. This says
            // the owner did not turn this on, which is a different fact and the
            // one somebody debugging needs.
            abort(403, 'This app does not track location.');
        }

        return [$app, $manifest, $policy];
    }
}
