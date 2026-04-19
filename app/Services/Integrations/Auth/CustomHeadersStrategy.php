<?php

namespace App\Services\Integrations\Auth;

class CustomHeadersStrategy implements AuthStrategy
{
    public function apply(array $authConfig): array
    {
        $headers = [];
        foreach ($authConfig['headers'] ?? [] as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $value = (string) ($entry['value'] ?? '');
            if ($name === '') {
                continue;
            }
            $headers[$name] = $value;
        }

        return ['headers' => $headers, 'query' => []];
    }
}
