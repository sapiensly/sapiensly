<?php

namespace App\Services\Integrations\Auth;

class NoneStrategy implements AuthStrategy
{
    public function apply(array $authConfig): array
    {
        return ['headers' => [], 'query' => []];
    }
}
