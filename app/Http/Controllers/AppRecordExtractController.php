<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\AppFile;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read a document, fill a form.
 *
 * Returns values and writes NOTHING. That is the whole shape of the feature: a
 * model read a crumpled receipt in bad light, and the person holding it is the
 * one who knows whether it says 1,250 or 7,250. Filling a form somebody then
 * checks is help; writing a record they never saw is a liability with a good
 * demo.
 *
 * So this needs the CREATE permission rather than a read: it exists to put
 * values in a form whose submit will need it, and offering it to somebody who
 * could not save the result would be a dead end with a model bill attached.
 */
class AppRecordExtractController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly RecordExtractionService $extractor,
    ) {}

    public function __invoke(Request $request, string $appSlug, string $objectSlug): JsonResponse
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

        $data = $request->validate([
            'file_id' => ['required', 'string', 'regex:/^fil_[a-z0-9]+$/'],
        ]);

        $object = collect($manifest['objects'] ?? [])->firstWhere('slug', $objectSlug);
        if ($object === null) {
            throw new NotFoundHttpException('No such object here.');
        }

        $previewRole = (string) $request->query('as_role', '');
        $access = $this->accessResolver->resolve($app, $manifest, $user, $previewRole !== '' ? $previewRole : null);

        abort_unless($access->hasAccess && $access->can($object['id'], 'create'), 403);

        // The file has to belong to THIS app. A file id is guessable enough that
        // reading somebody else's invoice through a model would otherwise be a
        // request away.
        $file = AppFile::query()
            ->where('app_id', $app->id)
            ->whereKey($data['file_id'])
            ->first();

        if ($file === null) {
            throw new NotFoundHttpException('No such file here.');
        }

        // Audio is read in two steps — transcribe, then read the transcript —
        // and the transcript comes back so the person can SEE what was heard.
        // A wrong field with no transcript beside it is a mystery.
        $result = str_starts_with((string) $file->mime, 'audio/')
            ? $this->extractor->extractFromSpeech($file, $object, $user)
            : $this->extractor->extract($file, $object, $user);

        return response()->json([
            'values' => $result['values'],
            'transcript' => $result['transcript'] ?? null,
            // Reported rather than thrown: a form that could not be filled is
            // still a form, and the person can type. Every capture in this wave
            // ends somewhere usable.
            'error' => $result['error'],
        ]);
    }
}
