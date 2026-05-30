<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\AppFile;
use App\Services\Storage\TenantStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * File upload/serve for App runtime forms. Lives under the same /r/{app_slug}
 * tenant gate as the runtime page itself — uploads always belong to an App,
 * and serving an asset re-checks that the requesting user can see the App.
 *
 * Storage layout: `app_uploads/{app_id}/{fil_id}.{ext}` on the tenant's
 * S3 disk (resolved by TenantStorage). Bytes never live under public/ — the
 * Vue file input POSTs here, gets back a {file_id, url, ...} payload it
 * stores in form state, and the form save dehydrates that payload as the
 * JSONB value for the field. If no S3 is configured (tenant- or global-
 * level) the upload is refused with HTTP 503 instead of silently falling
 * back to local storage that would leak across deploys.
 *
 * Global cap: any single upload is bounded by both the per-field max_size_mb
 * (validated against the manifest) and a hard server cap defined here.
 */
class AppFileController extends Controller
{
    /** Absolute hard ceiling, regardless of field config. */
    private const MAX_BYTES = 100 * 1024 * 1024; // 100 MB

    public function __construct(private TenantStorage $tenantStorage) {}

    public function upload(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolveApp($request, $appSlug);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (self::MAX_BYTES / 1024)],
        ]);

        $uploaded = $request->file('file');
        if ($uploaded === null) {
            throw new HttpException(400, 'No file provided.');
        }

        // Resolve the tenant disk up front — throws TenantStorageNotConfigured
        // (→ 503) if there's no S3 wired in, which is cleaner than persisting
        // a row and discovering later we can't actually store the bytes.
        $diskName = $this->tenantStorage->diskName($app);

        // Generate the AppFile row first so we have a stable ULID for the
        // filename — keeps the storage path tied to the DB row even if the
        // upload retry-loops.
        $file = new AppFile([
            'app_id' => $app->id,
            'organization_id' => $app->organization_id ?? null,
            'disk' => $diskName,
            'original_name' => $uploaded->getClientOriginalName(),
            'mime' => $uploaded->getClientMimeType(),
            'size_bytes' => $uploaded->getSize(),
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        // HasPrefixedUlid sets `id` on `creating`; we need it BEFORE persisting
        // because the storage path embeds it. Touch the id directly so the
        // value used on disk matches the row that will be saved.
        $file->id = AppFile::generatePrefixedUlid();

        $ext = pathinfo($uploaded->getClientOriginalName(), PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) $ext);
        $relativePath = "app_uploads/{$app->id}/{$file->id}".($ext !== '' ? '.'.strtolower($ext) : '');

        Storage::disk($diskName)->putFileAs(
            dirname($relativePath),
            $uploaded,
            basename($relativePath),
        );

        $file->storage_path = $relativePath;
        $file->save();

        return new JsonResponse([
            'file_id' => $file->id,
            'original_name' => $file->original_name,
            'mime' => $file->mime,
            'size_bytes' => $file->size_bytes,
            'url' => route('apps.runtime.files', ['app_slug' => $app->slug, 'file_id' => $file->id]),
        ], 201);
    }

    /**
     * Serve a previously-uploaded file. Path is `/r/{app_slug}/files/{file_id}`.
     * The user must still see the App to download — covers the case where a
     * record is shared but the receiving user lost access to the App itself.
     */
    public function show(Request $request, string $appSlug, string $fileId): StreamedResponse
    {
        $app = $this->resolveApp($request, $appSlug);

        $file = AppFile::query()
            ->where('id', $fileId)
            ->where('app_id', $app->id)
            ->first();

        if ($file === null) {
            throw new NotFoundHttpException("File '{$fileId}' not found.");
        }

        // Old rows from before TenantStorage existed might have an empty
        // disk — fall back to the currently-resolved disk for the App.
        $disk = Storage::disk($file->disk ?: $this->tenantStorage->diskName($app));
        if (! $disk->exists($file->storage_path)) {
            throw new NotFoundHttpException("File '{$fileId}' is missing on disk.");
        }

        return $disk->response(
            $file->storage_path,
            $file->original_name,
            ['Content-Type' => $file->mime ?: 'application/octet-stream'],
        );
    }

    private function resolveApp(Request $request, string $appSlug): App
    {
        $user = $request->user();
        $app = App::query()
            ->forAccountContext($user)
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        return $app;
    }
}
