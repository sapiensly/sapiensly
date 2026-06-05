<?php

namespace App\Facades;

use App\Support\Tenancy\TenantCache as TenantCacheService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool put(string $key, mixed $value, ?int $ttl = null)
 * @method static bool add(string $key, mixed $value, ?int $ttl = null)
 * @method static bool forever(string $key, mixed $value)
 * @method static mixed remember(string $key, ?int $ttl, \Closure $callback)
 * @method static mixed rememberForever(string $key, \Closure $callback)
 * @method static bool has(string $key)
 * @method static bool missing(string $key)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static bool forget(string $key)
 * @method static int|bool increment(string $key, int $value = 1)
 * @method static int|bool decrement(string $key, int $value = 1)
 * @method static string scopedKey(string $key)
 * @method static string prefix()
 * @method static TenantCacheService forOwner(?string $organizationId, ?int $userId)
 *
 * @see TenantCacheService
 */
class TenantCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantCacheService::class;
    }
}
