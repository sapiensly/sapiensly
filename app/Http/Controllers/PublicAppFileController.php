<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\AppFile;
use App\Services\Apps\AppAccessResolver;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File upload and download for a PUBLIC PORTAL.
 *
 * A file field on a public form used to fail outright: the runtime posts to the
 * session-authenticated endpoint, and a visitor has no session. This is the
 * portal-gated equivalent, and it is deliberately much narrower — writable
 * storage reachable by anyone on the internet is the most abusable surface in
 * the product, so it is bounded on every axis that matters:
 *
 *  - Only when `permissions.public.allow_writes` is on. A read-only portal
 *    accepts nothing.
 *  - A hard size ceiling far below the authenticated one: a stranger's
 *    attachment is an order form, not a video.
 *  - An extension allowlist, because "whatever the browser labelled it" is not
 *    a type check. Anything scriptable is refused by name.
 *  - Serving requires the file to belong to THIS app, and the portal to still
 *    be open — taking a portal down takes its files down with it.
 */
class PublicAppFileController extends Controller
{
    /** A visitor's attachment: a document or a photo, not a media library. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = [
        'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'heic',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt',
    ];

    public function __construct(
        private readonly TenantStorage $tenantStorage,
        private readonly AppAccessResolver $accessResolver,
    ) {}

    public function upload(Request $request, string $publicSlug): JsonResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');
        /** @var array<string, mixed> $manifest */
        $manifest = $request->attributes->get('publicAppManifest');

        if (($manifest['permissions']['public']['allow_writes'] ?? false) !== true) {
            return new JsonResponse([
                'error' => 'read_only',
                'message' => 'This page does not accept uploads.',
            ], 403);
        }

        if (! $this->accessResolver->resolvePublic($manifest)->hasAccess) {
            abort(404);
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) (self::MAX_BYTES / 1024),
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ]);

        $uploaded = $request->file('file');

        try {
            $diskName = $this->tenantStorage->diskName($app);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'storage_unavailable',
                'message' => 'Uploads are not available right now.',
            ], 503);
        }

        $file = new AppFile([
            'app_id' => $app->id,
            'organization_id' => $app->organization_id,
            'disk' => $diskName,
            'original_name' => $uploaded->getClientOriginalName(),
            'mime' => $uploaded->getClientMimeType(),
            'size_bytes' => $uploaded->getSize(),
            // No uploader: a visitor has no account, and inventing one would
            // attribute a stranger's file to a real person.
            'uploaded_by_user_id' => null,
        ]);
        $file->id = AppFile::generatePrefixedUlid();

        // The extension is rebuilt from the allowlist rather than taken from the
        // name: a filename is attacker-controlled and ends up on a disk path.
        $ext = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $uploaded->getClientOriginalExtension()));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $ext = 'bin';
        }

        $relativePath = TenantPath::scope(
            $app->organization_id,
            $app->user_id,
            "app_uploads/{$app->id}/{$file->id}.{$ext}",
        );

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
            'url' => route('portal.public.files', [
                'public_slug' => $app->public_slug,
                'file_id' => $file->id,
            ]),
        ], 201);
    }

    /**
     * Serve a file back to the portal. Unguessable by id and scoped to the app,
     * which is the same contract the authenticated runtime offers — the portal
     * being open is the additional condition, and BindPublicAppContext has
     * already checked it.
     */
    public function show(Request $request, string $publicSlug, string $fileId): StreamedResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');

        $file = AppFile::query()
            ->where('id', $fileId)
            ->where('app_id', $app->id)
            ->first();

        abort_if($file === null, 404);

        $disk = $this->tenantStorage->diskFromName($file->disk ?: $this->tenantStorage->diskName($app));
        abort_unless($disk->exists($file->storage_path), 404);

        return $disk->response(
            $file->storage_path,
            $file->original_name,
            [
                'Content-Type' => $file->mime ?: 'application/octet-stream',
                // A visitor-uploaded file served inline is a stored-XSS vector
                // on the portal's own origin. It downloads, always.
                'Content-Disposition' => 'attachment; filename="'.addslashes((string) $file->original_name).'"',
            ],
        );
    }
}
