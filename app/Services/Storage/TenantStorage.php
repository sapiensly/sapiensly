<?php

namespace App\Services\Storage;

use App\Exceptions\TenantStorageNotConfiguredException;
use App\Models\App;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudProviderService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves which Storage disk to use for user-generated files (chat
 * attachments, builder screenshots, App runtime file-field uploads, etc).
 *
 * Resolution is owner-aware and mirrors the knowledge-base / document storage
 * path: a configured CloudProvider at the org (tenant) or personal (user)
 * level is preferred, so a tenant's files land in their own bucket. The
 * priority order is:
 *   1. CloudProvider — org tenant → personal user → global, via
 *      {@see CloudProviderService::resolveStorageFor()}. The provider's disk is
 *      registered under a deterministic name (`cloud_provider_{id}`) so the
 *      persisted name round-trips back to the same disk on the serve/read path
 *      in any process (HTTP request or queue worker).
 *   2. Global S3 disk — used when AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and
 *      AWS_BUCKET are all set in the environment but no CloudProvider exists.
 *   3. Refuse — throw TenantStorageNotConfiguredException so the controller
 *      surfaces a 503. We never silently fall back to the local disk because
 *      files would land on the app server's ephemeral storage and disappear
 *      after a deploy/scale event.
 *
 * Already-stored rows keep whatever disk name they were written with (`s3`,
 * or a `cloud_provider_*` name), so serving old files is unaffected.
 */
class TenantStorage
{
    public function __construct(private readonly CloudProviderService $cloudProviders) {}

    /**
     * Disk name to write to and persist on the row, resolved for an App's owner.
     */
    public function diskName(?App $app = null): string
    {
        return $this->diskNameForOwner($app?->organization_id, $app?->user_id);
    }

    /**
     * Owner-aware disk name to write to and persist. When a CloudProvider is
     * resolved its disk is registered in this process so the returned name is
     * immediately usable with `Storage::disk()`.
     */
    public function diskNameForOwner(?string $organizationId, ?int $userId): string
    {
        $provider = $this->resolveOwnerProvider($organizationId, $userId);
        if ($provider !== null) {
            return $this->cloudProviders->registerDisk($provider);
        }

        if ($this->globalS3Configured()) {
            return 's3';
        }

        throw new TenantStorageNotConfiguredException;
    }

    /**
     * Resolve a Filesystem from a persisted disk name, re-registering the
     * CloudProvider-backed disk first so the name resolves in this process.
     */
    public function diskFromName(string $diskName): Filesystem
    {
        return Storage::disk($this->cloudProviders->ensureDiskRegistered($diskName));
    }

    /**
     * Resolved disk instance for an App's owner, ready to read/write against.
     */
    public function disk(?App $app = null): Filesystem
    {
        return Storage::disk($this->diskName($app));
    }

    /**
     * Ensure a persisted disk name resolves in the current process (e.g. inside
     * a queue worker that didn't register it on write). Returns the name so it
     * can be passed straight to `Storage::disk()` or the AI SDK's StoredImage.
     */
    public function ensureRegistered(string $diskName): string
    {
        return $this->cloudProviders->ensureDiskRegistered($diskName);
    }

    /**
     * Whether storage is configured at all — useful for healthchecks and for
     * tools that want to short-circuit without forcing a throw.
     */
    public function isConfigured(?App $app = null): bool
    {
        return $this->resolveOwnerProvider($app?->organization_id, $app?->user_id) !== null
            || $this->globalS3Configured();
    }

    /**
     * Resolve the storage CloudProvider for an owner context (org tenant →
     * personal user → global), or null when none is configured.
     */
    private function resolveOwnerProvider(?string $organizationId, ?int $userId): ?CloudProvider
    {
        $organization = $organizationId ? Organization::find($organizationId) : null;
        $user = ($organization === null && $userId !== null) ? User::find($userId) : null;

        return $this->cloudProviders->resolveStorageFor($organization, $user);
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
