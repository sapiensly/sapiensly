<?php

namespace App\Services\Integrations\Auth;

class ApiKeyStrategy implements AuthStrategy
{
    public function apply(array $authConfig): array
    {
        $location = $authConfig['location'] ?? 'header';
        $name = (string) ($authConfig['name'] ?? '');
        $value = (string) ($authConfig['value'] ?? '');

        if ($name === '' || $value === '') {
            return ['headers' => [], 'query' => []];
        }

        return $location === 'query'
            ? ['headers' => [], 'query' => [$name => $value]]
            : ['headers' => [$name => $value], 'query' => []];
    }
}
