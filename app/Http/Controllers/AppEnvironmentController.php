<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\Record;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\DemoDataGenerator;
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
        [$app] = $this->resolveForWrite($request, $appSlug);
        $this->emptyDemo($app);

        return back();
    }

    /**
     * Fills the sandbox with believable sample records.
     *
     * The way IN needs one. An empty demo is not a demo — somebody who opens
     * it to try the app finds a set of blank screens and learns nothing, and
     * the tool that seeds it lives over MCP where nobody using the app will
     * ever find it.
     */
    public function seed(Request $request, string $appSlug): RedirectResponse
    {
        [$app, $manifest] = $this->resolveForWrite($request, $appSlug);

        // Everything the generator writes goes to the sandbox by its own
        // doing; the guard here is about the BUTTON, so it can never be
        // pressed from a page showing real records.
        app(DemoDataGenerator::class)->generate($app, $manifest, 8, null, $request->user());

        return back();
    }

    private function emptyDemo(App $app): void
    {
        do {
            $deleted = DB::connection((new Record)->getConnectionName())
                ->table((new Record)->getTable())
                ->where('app_id', $app->id)
                ->where('environment', EnvironmentContext::DEMO)
                ->limit(self::BATCH)
                ->delete();
        } while ($deleted === self::BATCH);
    }

    /**
     * The app, its manifest, and the two checks every write here shares.
     *
     * @return array{0: App, 1: array<string, mixed>}
     */
    private function resolveForWrite(Request $request, string $appSlug): array
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

        abort_unless($this->accessResolver->resolve($app, $manifest, $user)->bypass, 403);

        // And only while standing in it. The environment is a mode, so "am I
        // about to touch the real records?" is a question the server answers
        // rather than the person clicking.
        abort_unless($this->environment->isDemo(), 403, 'Only the demo environment can be changed here.');

        return [$app, $manifest];
    }
}
