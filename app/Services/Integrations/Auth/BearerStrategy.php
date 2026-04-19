<?php

namespace App\Services\Integrations\Auth;

class BearerStrategy implements AuthStrategy
{
    public function apply(array $authConfig): array
    {
        $token = (string) ($authConfig['token'] ?? '');

        if ($token === '') {
            return ['headers' => [], 'query' => []];
        }

        return [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'query' => [],
        ];
    }
}
