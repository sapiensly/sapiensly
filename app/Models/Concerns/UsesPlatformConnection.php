<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\Schemas;

/**
 * Pins a model to the `platform` runtime connection (role `platform_app`,
 * `platform` schema, no RLS — isolation is structural). This is also the
 * application default connection, so the trait is mostly documentation: it makes
 * a model's control-plane placement explicit and survives a future change of the
 * default connection.
 */
trait UsesPlatformConnection
{
    public function initializeUsesPlatformConnection(): void
    {
        $this->setConnection(Schemas::PLATFORM_CONNECTION);
    }
}
