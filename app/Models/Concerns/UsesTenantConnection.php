<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\Schemas;

/**
 * Pins a model to the `tenant` runtime connection (role `tenant_app`, `tenant`
 * schema, RLS-enforced). Applied to every model whose table is listed in
 * {@see Schemas::tenantTables()}.
 *
 * Uses Eloquent's `initialize{Trait}` hook rather than a `$connection` property
 * so it never conflicts with a model that already declares one.
 */
trait UsesTenantConnection
{
    public function initializeUsesTenantConnection(): void
    {
        $this->setConnection(Schemas::TENANT_CONNECTION);
    }
}
