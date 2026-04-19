<?php

namespace App\Services\Integrations\Auth;

/**
 * Contract for applying an authentication scheme to an outbound HTTP request.
 * Implementations return the triple of headers / query params / options to
 * merge into the Laravel Http client before dispatch.
 */
interface AuthStrategy
{
    /**
     * @param  array<string, mixed>  $authConfig  Decrypted integration.auth_config payload
     * @return array{headers: array<string, string>, query: array<string, string>}
     */
    public function apply(array $authConfig): array;
}
