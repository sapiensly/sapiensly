<?php

namespace App\Support\Storage;

use App\Models\Concerns\HasVisibility;
use InvalidArgumentException;

/**
 * Builds the uniform per-tenant storage prefix so every file written to a
 * SHARED bucket (the global/env disk used by tenants without their own storage)
 * is partitioned by its owning tenant. Mirrors the RLS account context used by
 * {@see HasVisibility::scopeForAccountContext}: business
 * mode (organization present) → `org/{id}`, personal mode → `user/{id}`.
 *
 * Without a uniform prefix, every tenant's files share one flat keyspace; with
 * it, a bucket-level listing stays partitioned and lifecycle/retention/audit can
 * be scoped per tenant. Applied unconditionally (a tenant's own BYOS bucket also
 * gets the prefix — harmless and keeps reads/writes consistent across disks).
 */
final class TenantPath
{
    /**
     * The tenant prefix for an owner context. Fail-closed: refuses to build a
     * prefix (and thus to write to the bucket root) without an org or user.
     */
    public static function prefix(?string $organizationId, ?int $userId): string
    {
        if ($organizationId !== null && $organizationId !== '') {
            return 'org/'.$organizationId;
        }

        if ($userId !== null) {
            return 'user/'.$userId;
        }

        throw new InvalidArgumentException(
            'A tenant storage prefix requires an organization or a user — refusing to write to the bucket root.'
        );
    }

    /**
     * Prepend the tenant prefix to a content-relative path, e.g.
     * `documents/{id}/file.pdf` → `org/{orgId}/documents/{id}/file.pdf`.
     */
    public static function scope(?string $organizationId, ?int $userId, string $relativePath): string
    {
        return self::prefix($organizationId, $userId).'/'.ltrim($relativePath, '/');
    }
}
