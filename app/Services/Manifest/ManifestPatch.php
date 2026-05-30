<?php

namespace App\Services\Manifest;

use gamringer\JSONPatch\Patch;

/**
 * Applies an RFC 6902 JSON Patch to a manifest array.
 *
 * Normalises the document first so optional top-level collections exist as
 * arrays. Without this, the underlying library silently DROPS an append like
 * `add /workflows/-` when the `workflows` key is absent (a brand-new app with
 * no automations yet): the patch reports success but the value vanishes, which
 * then surfaces far downstream as an "unknown workflow" reference. Seeding the
 * empty array makes the natural `/workflows/-` append work — important because
 * both the builder AI's propose_change and AppManifestService::applyPatch route
 * through here.
 */
final class ManifestPatch
{
    /** Optional top-level array containers that may be absent on a fresh manifest. */
    private const OPTIONAL_ARRAYS = ['workflows'];

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, mixed>
     */
    public static function apply(array $document, array $ops): array
    {
        foreach (self::OPTIONAL_ARRAYS as $key) {
            if (! array_key_exists($key, $document)) {
                $document[$key] = [];
            }
        }

        $target = json_decode(json_encode($document, JSON_THROW_ON_ERROR));
        $patch = Patch::fromJSON(json_encode($ops, JSON_THROW_ON_ERROR));
        $patch->apply($target);

        return json_decode(json_encode($target, JSON_THROW_ON_ERROR), true);
    }
}
