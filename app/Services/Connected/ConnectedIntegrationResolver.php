<?php

namespace App\Services\Connected;

use App\Models\App;
use App\Models\Integration;

/**
 * Resolves the integration backing a connected object, scoped to the app's
 * tenant (mirrors HasVisibility::forAccountContext without needing the User).
 * Shared by the read runtime (BlockDataResolver) and the write runtime
 * (AppActionController) so a connected object always reaches the external
 * system through the owning tenant's own connection — never another tenant's.
 */
class ConnectedIntegrationResolver
{
    public function resolve(App $app, ?string $integrationId): ?Integration
    {
        if ($integrationId === null) {
            return null;
        }

        $query = Integration::query()->where('id', $integrationId);

        if ($app->organization_id !== null) {
            $query->where('organization_id', $app->organization_id);
        } else {
            $query->whereNull('organization_id')->where('user_id', $app->user_id);
        }

        return $query->first();
    }
}
