<?php

namespace App\Services\Landing;

use App\Models\App;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredImage;

/**
 * Resolves the app's freshest preview screenshot (uploaded by the builder UI
 * after each applied version) for the design director's pixel judgment. Null
 * when none exists or it's stale — the critique then degrades to text-only.
 */
class LatestPreviewShot
{
    /** A shot older than this reflects a design too many edits ago to trust. */
    private const FRESH_MINUTES = 15;

    public function __construct(private readonly TenantStorage $tenantStorage) {}

    public function for(App $app): ?StoredImage
    {
        // EVERYTHING inside the try: diskName() itself throws when no tenant
        // storage is configured (local without S3), and a missing shot must
        // NEVER break the critique — it just degrades to text-only.
        try {
            $disk = $this->tenantStorage->diskName($app);
            $path = TenantPath::scope($app->organization_id, $app->user_id, 'builder_screenshots/'.$app->id.'/latest_preview.jpg');

            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }
            $modified = Storage::disk($disk)->lastModified($path);
            if (now()->timestamp - $modified > self::FRESH_MINUTES * 60) {
                return null;
            }

            return new StoredImage($path, $disk);
        } catch (\Throwable) {
            return null;
        }
    }
}
