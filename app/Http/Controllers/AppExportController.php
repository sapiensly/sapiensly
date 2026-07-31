<?php

namespace App\Http\Controllers;

use App\Jobs\RunRecordExportJob;
use App\Models\App;
use App\Models\AppExport;
use App\Services\Apps\AppAccessContext;
use App\Services\Apps\AppAccessResolver;
use App\Services\Export\RecordExporter;
use App\Services\Manifest\AppManifestService;
use App\Services\Storage\TenantStorage;
use Illuminate\Http\JsonResponse;
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
        [$app, $manifest, $access, $object] = $this->resolve($request, $appSlug, $objectSlug);

        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        // Page params drive the same filters the screen had, so "export what I
        // am looking at" is what actually happens.
        $context = [
            'current_user' => $request->user() !== null
                ? ['id' => $request->user()->id, 'email' => $request->user()->email]
                : null,
            'params' => array_filter($request->query(), fn ($v) => is_string($v) || is_array($v)),
            '__access' => $access,
            '__actor' => $request->user(),
        ];

        // Memory is flat at any size, so the ceiling here is purely the clock:
        // past it the request timeout is the real risk, and the caller is sent
        // to the queued route rather than handed a download that may die
        // half-written. Refuse, never truncate.
        $total = $this->exporter->countFor($app, $object, $manifest, $context);
        if ($total > RecordExporter::DIRECT_MAX_ROWS) {
            abort(422, "That is {$total} rows — too many to download in one request. Start a prepared export instead (POST .../export/queue) and download it when it is ready.");
        }

        try {
            return $this->exporter->stream($app, $object, $manifest, $context, $format);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * Start a prepared export. Returns the run immediately; the file is built
     * by a job and downloaded from `download` once ready.
     */
    public function queue(Request $request, string $appSlug, string $objectSlug): JsonResponse
    {
        [$app, $manifest, $access, $object, $previewRole] = $this->resolve($request, $appSlug, $objectSlug);

        try {
            $disk = app(TenantStorage::class)->diskName($app);
        } catch (\Throwable) {
            $disk = 'local';
        }

        $export = AppExport::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'object_id' => $object['id'],
            'object_name' => $object['name'] ?? $object['slug'] ?? null,
            'format' => $request->query('format') === 'xlsx' ? 'xlsx' : 'csv',
            'role_slug' => $previewRole !== '' ? $previewRole : ($access->roleSlugs[0] ?? null),
            'requested_by_user_id' => $request->user()?->id,
            'disk' => $disk,
            'status' => 'queued',
        ]);

        RunRecordExportJob::dispatch(
            $export->id,
            $app->id,
            $request->user()?->id,
            $previewRole !== '' ? $previewRole : null,
            array_filter($request->query(), fn ($v) => is_string($v) || is_array($v)),
        );

        return response()->json(['export' => $export->toProgress()], 202);
    }

    /** Where a prepared export is up to. */
    public function status(Request $request, string $appSlug, string $objectSlug, string $exportId): JsonResponse
    {
        [$app] = $this->resolve($request, $appSlug, $objectSlug);

        $export = AppExport::query()->where('app_id', $app->id)->where('id', $exportId)->first();

        return $export === null
            ? response()->json(['message' => 'No such export.'], 404)
            : response()->json(['export' => $export->toProgress()]);
    }

    /**
     * Hand over the finished file.
     *
     * Re-checks read permission on the object NOW, not only when the export was
     * requested: a finished file is a frozen answer to "what could that role
     * see", and a role narrowed since must not be able to collect it anyway.
     */
    public function download(Request $request, string $appSlug, string $objectSlug, string $exportId): StreamedResponse
    {
        [$app] = $this->resolve($request, $appSlug, $objectSlug);

        $export = AppExport::query()->where('app_id', $app->id)->where('id', $exportId)->first();
        if ($export === null || ! $export->isDownloadable()) {
            throw new NotFoundHttpException('That export is not available.');
        }

        $disk = app(TenantStorage::class)->diskFromName((string) $export->disk);
        if (! $disk->exists((string) $export->storage_path)) {
            throw new NotFoundHttpException('That export is no longer stored.');
        }

        return $disk->download((string) $export->storage_path, (string) $export->file_name);
    }

    /**
     * The shared gate. Same answer for "no such object" and "not allowed to
     * read it": which objects exist is not something a restricted role should
     * learn from a URL.
     *
     * @return array{0: App, 1: array<string, mixed>, 2: AppAccessContext, 3: array<string, mixed>, 4: string}
     */
    private function resolve(Request $request, string $appSlug, string $objectSlug): array
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

        $previewRole = (string) $request->query('as_role', '');
        $access = $this->accessResolver->resolve($app, $manifest, $user, $previewRole !== '' ? $previewRole : null);

        $object = null;
        foreach ($manifest['objects'] ?? [] as $candidate) {
            if (($candidate['slug'] ?? null) === $objectSlug) {
                $object = $candidate;
                break;
            }
        }

        if ($object === null || ! $access->hasAccess || ! $access->can($object['id'], 'read')) {
            throw new NotFoundHttpException('Nothing to export here.');
        }

        return [$app, $manifest, $access, $object, $previewRole];
    }
}
