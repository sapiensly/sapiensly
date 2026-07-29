<?php

namespace App\Services\Branding;

use App\Models\Organization;
use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a logo or icon a site proposal found onto the tenant's own disk.
 *
 * The brand must not depend on somebody else's server staying up: a logo left
 * pointing at a third party breaks the day they reorganize their CDN, and it
 * appears in app headers, chatbot widgets on external sites and decks. So the
 * bytes are adopted at the moment a proposal is accepted — never while merely
 * proposing, which must write nothing anywhere.
 *
 * Shared by the settings page and the MCP import so both make the same
 * decisions about what may be adopted. SVG is deliberately NOT importable even
 * though an upload accepts it: an upload is a file a human deliberately picked,
 * while this takes whatever a third-party host serves, and these assets are
 * streamed back from our own origin by an unauthenticated route.
 */
class BrandAssetImporter
{
    /** Content storage prefix (under the tenant partition) for brand assets. */
    public const ASSET_DIR = 'org-brand';

    /**
     * Remote Content-Type → the extension we store it under. `.ico` is here
     * because a site's `<link rel="icon">` very often is one.
     */
    private const IMPORTABLE_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /** Matches the 2 MB ceiling the upload path enforces through validation. */
    private const MAX_ASSET_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly TenantStorage $tenantStorage,
        private readonly SafeHttpClient $http,
    ) {}

    /**
     * Adopt a remote image and return the URL it is served from here.
     *
     * @param  'logo'|'icon'  $kind
     *
     * @throws BrandAssetImportFailed with a message meant for whoever asked
     */
    public function import(Organization $organization, string $kind, string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 15]);
        } catch (Throwable) {
            throw new BrandAssetImportFailed(__('That image could not be downloaded.'));
        }

        if (! $response->successful()) {
            throw new BrandAssetImportFailed(__('That image could not be downloaded.'));
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $ext = self::IMPORTABLE_MIME[$contentType] ?? null;

        if ($ext === null) {
            throw new BrandAssetImportFailed(__(
                'That file type cannot be imported (:type). Upload the image instead.',
                ['type' => $contentType ?: 'unknown'],
            ));
        }

        $bytes = $response->body();
        if (strlen($bytes) > self::MAX_ASSET_BYTES) {
            throw new BrandAssetImportFailed(__('That image is too large to import.'));
        }

        $filename = $kind.'-'.Str::lower((string) Str::ulid()).'.'.$ext;
        $diskName = $this->tenantStorage->diskNameForOwner($organization->id, null);
        $relativePath = TenantPath::scope($organization->id, null, self::ASSET_DIR.'/'.$filename);

        $this->tenantStorage->diskFromName($diskName)->put($relativePath, $bytes);

        return route('organization.brand.asset.show', [
            'organization' => $organization->id,
            'filename' => $filename,
        ]);
    }
}
