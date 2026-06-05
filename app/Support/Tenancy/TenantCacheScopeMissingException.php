<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-scoped cache operation runs with no tenant scope set.
 *
 * Mirrors the database's fail-closed posture: with empty RLS GUCs a tenant
 * query yields zero rows rather than leaking, so a tenant cache lookup with no
 * scope must refuse rather than fall back to a shared, cross-tenant key.
 */
class TenantCacheScopeMissingException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No tenant scope is set — refusing a tenant-scoped cache operation. '.
            'Set a scope via TenantContext (HTTP/queue middleware do this automatically) '.
            'or use TenantCache::forOwner($organizationId, $userId) for an explicit scope.'
        );
    }
}
