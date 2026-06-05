<?php

namespace App\Support\Tenancy;

use App\Jobs\Middleware\EstablishTenantContext;
use App\Models\Concerns\HasVisibility;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-scoped cache: the Redis-layer analog to Row-Level Security.
 *
 * Where the database isolates tenants structurally (separate roles + RLS, so a
 * mis-scoped query fails loudly), the cache is a single shared keyspace where
 * isolation would otherwise depend on every caller remembering to namespace
 * their keys. This wrapper removes that judgement call: every key is
 * transparently prefixed with the current tenant scope, derived from
 * {@see TenantContext} exactly as {@see HasVisibility::scopeForAccountContext}
 * derives it — `organization_id` in business mode, `user_id` in personal mode.
 *
 * Fail-closed: with no scope set, every operation throws
 * {@see TenantCacheScopeMissingException} rather than silently using a shared
 * key, mirroring RLS yielding zero rows on empty GUCs. Use this for any cached
 * value derived from tenant row-data; keep {@see Cache}
 * for genuinely global (platform / control-plane) values.
 */
class TenantCache
{
    /**
     * @param  bool  $hasExplicitScope  When true the scope comes from the
     *                                  constructor args, not the ambient
     *                                  TenantContext (see {@see self::forOwner()}).
     */
    public function __construct(
        private readonly Repository $store,
        private readonly TenantContext $context,
        private readonly ?string $explicitOrganizationId = null,
        private readonly ?int $explicitUserId = null,
        private readonly bool $hasExplicitScope = false,
    ) {}

    /**
     * A copy bound to an explicit owner rather than the ambient scope — for
     * queued jobs or admin flows that act on a known tenant, mirroring
     * {@see EstablishTenantContext::fromOwner()}.
     */
    public function forOwner(?string $organizationId, ?int $userId): self
    {
        return new self($this->store, $this->context, $organizationId, $userId, true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->scopedKey($key), $default);
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->put($this->scopedKey($key), $value, $ttl);
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->add($this->scopedKey($key), $value, $ttl);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($this->scopedKey($key), $value);
    }

    /**
     * @template TValue
     *
     * @param  Closure():TValue  $callback
     * @return TValue
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        return $this->store->remember($this->scopedKey($key), $ttl, $callback);
    }

    /**
     * @template TValue
     *
     * @param  Closure():TValue  $callback
     * @return TValue
     */
    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->store->rememberForever($this->scopedKey($key), $callback);
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->scopedKey($key));
    }

    public function missing(string $key): bool
    {
        return ! $this->has($key);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->store->pull($this->scopedKey($key), $default);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($this->scopedKey($key));
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->store->increment($this->scopedKey($key), $value);
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->store->decrement($this->scopedKey($key), $value);
    }

    /**
     * The fully-qualified key a logical key maps to — useful for tests and
     * for callers that must hand a raw key to a lower-level primitive.
     */
    public function scopedKey(string $key): string
    {
        return $this->prefix().$key;
    }

    /**
     * The tenant prefix for the active scope, e.g. `t:org:{id}:` or
     * `t:user:{id}:`. Business mode (organization present) wins over personal
     * mode, matching the RLS policy precedence.
     *
     * @throws TenantCacheScopeMissingException when no scope is active
     */
    public function prefix(): string
    {
        [$organizationId, $userId] = $this->hasExplicitScope
            ? [$this->explicitOrganizationId, $this->explicitUserId]
            : [$this->context->organizationId(), $this->context->userId()];

        if ($organizationId !== null) {
            return "t:org:{$organizationId}:";
        }

        if ($userId !== null) {
            return "t:user:{$userId}:";
        }

        throw TenantCacheScopeMissingException::make();
    }
}
