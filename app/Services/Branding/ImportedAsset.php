<?php

namespace App\Services\Branding;

use App\Support\Branding\AssetTone;

/**
 * A brand image that made it into tenant storage, and what we could tell about
 * it on the way past.
 *
 * The tone travels with the URL because the moment we hold the bytes is the only
 * moment we can read it cheaply — afterwards the asset is a URL like any other,
 * and the caller would have to download its own file back to ask.
 */
final class ImportedAsset
{
    public function __construct(
        public readonly string $url,
        public readonly AssetTone $tone,
    ) {}
}
