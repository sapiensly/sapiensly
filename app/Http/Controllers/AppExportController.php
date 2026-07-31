<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessContext;
use App\Services\Apps\AppAccessResolver;
use App\Services\Export\RecordExporter;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Download an object's records as CSV or XLSX, from the authenticated runtime.
 *
 * Gated by the same {@see AppAccessContext} the page is: the
 * role must be able to READ the object, its row_filter narrows what leaves, and
 * its hidden fields never reach the file. An export that returned more than the
 * table showed would be the quietest possible way around a permission.
 *
 * Deliberately NOT offered on a public portal. A read grant to a stranger is a
 * grant to browse, not a lever to take the whole object in one request — the
 * two are different decisions and only the first has been made.
 */
class AppExportController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly RecordExporter $exporter,
    ) {}

    public function __invoke(Request $request, string $appSlug, string $objectSlug): StreamedResponse
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

        // `as_role` works here too, so "what does this role actually get to
        // download" is answerable without a throwaway account — the same
        // preview the runtime page offers, and just as unable to widen.
        $access = $this->accessResolver->resolve(
            $app,
            $manifest,
            $user,
            ($previewRole = (string) $request->query('as_role', '')) !== '' ? $previewRole : null,
        );

        $object = null;
        foreach ($manifest['objects'] ?? [] as $candidate) {
            if (($candidate['slug'] ?? null) === $objectSlug) {
                $object = $candidate;
                break;
            }
        }

        // One answer for "no such object" and "not allowed to read it": which
        // objects exist is not something a restricted role should learn from a
        // download URL.
        if ($object === null || ! $access->hasAccess || ! $access->can($object['id'], 'read')) {
            throw new NotFoundHttpException('Nothing to export here.');
        }

        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        // Page params drive the same filters the screen had, so "export what I
        // am looking at" is what actually happens.
        $context = [
            'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
            'params' => array_filter($request->query(), fn ($v) => is_string($v) || is_array($v)),
            '__access' => $access,
            '__actor' => $user,
        ];

        try {
            return $this->exporter->stream($app, $object, $manifest, $context, $format);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }
}
