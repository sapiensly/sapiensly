<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\Record;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\EnvironmentContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Emptying the sandbox.
 *
 * Only ever the sandbox, and only from inside it. Both halves matter: a
 * "reset" that could be aimed at production from a menu is one wrong click
 * from deleting a business's records, and the environment is remembered per
 * app precisely so nobody has to keep track of which one they are in. So the
 * request is refused unless the session says demo — the button is not the
 * decision, being there is.
 *
 * Deletes in batches. A tenant that has been demoing for a year can hold a lot
 * of rows, and one unbounded DELETE is a lock held across the table while
 * everybody else waits.
 */
class AppEnvironmentController extends Controller
{
    /** Rows per statement. Small enough to stay out of anybody's way. */
    private const BATCH = 500;

    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly EnvironmentContext $environment,
    ) {}

    public function reset(Request $request, string $appSlug): RedirectResponse
    {
        $user = $request->user();

        $app = App::query()->forAccountContext($user)->where('slug', $appSlug)->first();
        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$appSlug}' has no published manifest.");
        }

        // Whoever administers the app — the same people offered the switch.
        abort_unless($this->accessResolver->resolve($app, $manifest, $user)->bypass, 403);

        // And only while standing in it. This is the guard that matters: the
        // environment is a mode, so "am I about to wipe the real records?" is a
        // question the server answers rather than the person clicking.
        abort_unless($this->environment->isDemo(), 403, 'Only the demo environment can be reset.');

        do {
            $deleted = DB::connection((new Record)->getConnectionName())
                ->table((new Record)->getTable())
                ->where('app_id', $app->id)
                ->where('environment', EnvironmentContext::DEMO)
                ->limit(self::BATCH)
                ->delete();
        } while ($deleted === self::BATCH);

        return back();
    }
}
