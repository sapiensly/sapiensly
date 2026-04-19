<?php

namespace App\Services\Integrations\OAuth2;

use App\Models\Integration;

/**
 * Machine-to-machine OAuth2 flow. Acquires an access token using the stored
 * client_id + client_secret and caches it inside `integration.auth_config`.
 */
class OAuth2ClientCredentialsFlow
{
    public function __construct(
        private OAuth2TokenRefresher $refresher,
    ) {}

    public function acquire(Integration $integration): Integration
    {
        return $this->refresher->refreshIfNeeded($integration);
    }
}
