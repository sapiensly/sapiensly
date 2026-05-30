<?php

namespace App\Services\Storage;

use App\Exceptions\TenantStorageNotConfiguredException;
use App\Models\App;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves which Storage disk to use for an App's user-generated files
 * (chat attachments, screenshots, file-field uploads, etc).
 *
 * Policy (in priority order):
 *   1. Per-tenant override — placeholder for the future, when an Organization
 *      gets its own S3 bucket/credentials. The hook is here so we don't have
 *      to retrofit callers when the Organization model grows that column.
 *   2. Global S3 disk — used when AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY
 *      and AWS_BUCKET are all set in the environment.
 *   3. Refuse — throw TenantStorageNotConfiguredException so the controller
 *      surfaces a 503 with an actionable error. We never silently fall back
 *      to the local disk because files would land on the app server's
 *      ephemeral storage and disappear after a deploy/scale event.
 */
class TenantStorage
{
    /**
     * Disk name to persist on the row (so the serve endpoint knows which
     * disk to read from later, even if config changes).
     */
    public function diskName(?App $app = null): string
    {
        $tenantDisk = $this->resolveTenantDisk($app);
        if ($tenantDisk !== null) {
            return $tenantDisk;
        }

        if ($this->globalS3Configured()) {
            return 's3';
        }

        throw new TenantStorageNotConfiguredException;
    }

    /**
     * Resolved disk instance ready to read/write against.
     */
    public function disk(?App $app = null): Filesystem
    {
        return Storage::disk($this->diskName($app));
    }

    /**
     * Whether storage is configured at all — useful for healthchecks and
     * for tools that want to short-circuit without forcing a throw.
     */
    public function isConfigured(?App $app = null): bool
    {
        return $this->resolveTenantDisk($app) !== null
            || $this->globalS3Configured();
    }

    /**
     * Hook for per-tenant disk overrides. Returns null today because we
     * haven't built the Organization-level S3 config UI yet; the callsite
     * already expects null to mean "fall back to global".
     */
    protected function resolveTenantDisk(?App $app): ?string
    {
        if ($app === null) {
            return null;
        }

        // FUTURE: $app->organization?->s3_disk_name once the column exists.
        return null;
    }

    /**
     * Global S3 is considered "configured" when the three mandatory fields
     * (access key, secret, bucket) all have non-empty values. Region has a
     * sensible default and endpoint is optional (R2/MinIO).
     */
    protected function globalS3Configured(): bool
    {
        $cfg = config('filesystems.disks.s3');

        return is_array($cfg)
            && ! empty($cfg['key'])
            && ! empty($cfg['secret'])
            && ! empty($cfg['bucket']);
    }
}
