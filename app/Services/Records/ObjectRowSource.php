<?php

namespace App\Services\Records;

use App\Facades\TenantCache;
use App\Models\App;
use App\Models\User;

/**
 * A sample of an object's rows, whatever backs it.
 *
 * The analytic layer read only connected objects — straight through
 * ConnectedObjectReader — which made every native, record-backed object (the
 * ordinary case) invisible to it: the analyst could not see half of any given
 * app. This is the single port both kinds go through. BlockDataResolver already
 * resolves either source to rows, so the caller stops caring where the data
 * lives and reads the values back through {@see FieldPaths}.
 *
 * The sample is cached briefly: a panel that reopens, or several primitives
 * reading the same object in one pass, must not re-hit an external API.
 */
class ObjectRowSource
{
    /** Row-sample cache TTL (seconds) — an analysis pass is deliberate, not hot. */
    private const TTL = 120;

    public function __construct(private BlockDataResolver $blockData) {}

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    public function sample(App $app, array $object, array $manifest, ?User $actor, int $limit): array
    {
        $objectId = $object['id'] ?? null;
        if (! is_string($objectId) || $objectId === '') {
            return [];
        }

        $key = 'rows:sample:'.sha1($app->id.'|'.$objectId.'|'.($actor?->id ?? 'x').'|'.$limit);

        try {
            return TenantCache::remember($key, self::TTL, function () use ($app, $objectId, $manifest, $limit): array {
                $rows = $this->blockData->queryObject(
                    $app,
                    ['object_id' => $objectId, 'limit' => $limit],
                    $manifest,
                );

                // Unwrap the block envelope ({id, data}) down to the payload the
                // analytic primitives read: a connected object's payload is the
                // external row verbatim (addressed by external_path), an internal
                // one is slug-keyed (plus id and the sys_* stamps). One shape per
                // source, both resolved by {@see FieldPaths}.
                return array_values(array_map(
                    fn (array $row): array => is_array($row['data'] ?? null) ? $row['data'] : $row,
                    $rows,
                ));
            });
        } catch (\Throwable) {
            return []; // one source that won't read never sinks the whole analysis
        }
    }
}
