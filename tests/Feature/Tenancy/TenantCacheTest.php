<?php

use App\Facades\TenantCache;
use App\Support\Tenancy\TenantCache as TenantCacheService;
use App\Support\Tenancy\TenantCacheScopeMissingException;
use App\Support\Tenancy\TenantContext;

afterEach(function () {
    app(TenantContext::class)->forget();
});

test('two organizations never share a cache key', function () {
    $context = app(TenantContext::class);

    $context->set('org_a', null);
    TenantCache::put('greeting', 'hello-from-a');

    $context->set('org_b', null);
    expect(TenantCache::get('greeting'))->toBeNull();
    TenantCache::put('greeting', 'hello-from-b');

    $context->set('org_a', null);
    expect(TenantCache::get('greeting'))->toBe('hello-from-a');
});

test('two personal users never share a cache key', function () {
    $context = app(TenantContext::class);

    $context->set(null, 1);
    TenantCache::put('inbox', ['a']);

    $context->set(null, 2);
    expect(TenantCache::get('inbox'))->toBeNull();
});

test('business scope takes precedence over the personal user, matching RLS', function () {
    $cache = app(TenantCacheService::class);

    expect($cache->forOwner('org_x', 99)->scopedKey('k'))->toBe('t:org:org_x:k')
        ->and($cache->forOwner(null, 99)->scopedKey('k'))->toBe('t:user:99:k');
});

test('a personal user and an organization with the same id do not collide', function () {
    $cache = app(TenantCacheService::class);

    $cache->forOwner('7', null)->put('v', 'org-value');
    $cache->forOwner(null, 7)->put('v', 'user-value');

    expect($cache->forOwner('7', null)->get('v'))->toBe('org-value')
        ->and($cache->forOwner(null, 7)->get('v'))->toBe('user-value');
});

test('remember is scoped per tenant', function () {
    $cache = app(TenantCacheService::class);
    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return "value-{$calls}";
    };

    $a = $cache->forOwner('org_a', null)->remember('k', 60, $build);
    $b = $cache->forOwner('org_b', null)->remember('k', 60, $build);
    $aAgain = $cache->forOwner('org_a', null)->remember('k', 60, $build);

    expect($a)->toBe('value-1')
        ->and($b)->toBe('value-2')
        ->and($aAgain)->toBe('value-1')
        ->and($calls)->toBe(2);
});

test('forget only clears the calling tenant', function () {
    $cache = app(TenantCacheService::class);

    $cache->forOwner('org_a', null)->put('k', 'a');
    $cache->forOwner('org_b', null)->put('k', 'b');

    $cache->forOwner('org_a', null)->forget('k');

    expect($cache->forOwner('org_a', null)->has('k'))->toBeFalse()
        ->and($cache->forOwner('org_b', null)->get('k'))->toBe('b');
});

test('it fails closed when no tenant scope is set', function () {
    app(TenantContext::class)->forget();

    expect(fn () => TenantCache::put('k', 'v'))
        ->toThrow(TenantCacheScopeMissingException::class);
    expect(fn () => TenantCache::get('k'))
        ->toThrow(TenantCacheScopeMissingException::class);
});

test('forOwner overrides the ambient scope', function () {
    $context = app(TenantContext::class);
    $cache = app(TenantCacheService::class);

    $context->set('ambient_org', null);
    $cache->forOwner('explicit_org', null)->put('k', 'explicit');

    expect(TenantCache::get('k'))->toBeNull()
        ->and($cache->forOwner('explicit_org', null)->get('k'))->toBe('explicit');
});
