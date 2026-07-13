<?php

namespace App\Services\Records;

use App\Facades\TenantCache;
use App\Models\App;
use App\Models\User;
use App\Support\Tenancy\TenantCacheScopeMissingException;

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
        $read = function () use ($app, $objectId, $manifest, $actor, $limit): array {
            $rows = $this->blockData->queryObject(
                $app,
                ['object_id' => $objectId, 'limit' => $limit],
                $manifest,
                // The actor READS. A connected source behind per-user OAuth
                // authenticates as the viewer, so an analysis that forgot to hand
                // the actor down got no rows — and the swallow turned that into
                // "this app has no data", which is a lie about the app rather than
                // the truth about the credentials. The actor was being used for
                // the cache key and nothing else.
                $actor !== null ? ['__actor' => $actor] : [],
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
        };

        try {
            return TenantCache::remember($key, self::TTL, $read);
        } catch (TenantCacheScopeMissingException) {
            // No tenant scope in this context, so the cache fails closed by
            // design. The READ is still governed by RLS (and, for a connected
            // object, by the actor's own credentials), so serve it uncached
            // rather than reporting an empty source — a cache that can't be used
            // must not read as "this app has no data".
            try {
                return $read();
            } catch (\Throwable) {
                return [];
            }
        } catch (\Throwable) {
            return []; // one source that won't read never sinks the whole analysis
        }
    }
}
