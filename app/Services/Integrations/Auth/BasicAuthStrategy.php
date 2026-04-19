<?php

namespace App\Services\Integrations\Auth;

class BasicAuthStrategy implements AuthStrategy
{
    public function apply(array $authConfig): array
    {
        $username = (string) ($authConfig['username'] ?? '');
        $password = (string) ($authConfig['password'] ?? '');

        if ($username === '' && $password === '') {
            return ['headers' => [], 'query' => []];
        }

        return [
            'headers' => [
                'Authorization' => 'Basic '.base64_encode($username.':'.$password),
            ],
            'query' => [],
        ];
    }
}
