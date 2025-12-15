<?php

namespace App\Enums;

enum Visibility: string
{
    case Private = 'private';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Organization => 'Organization',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => 'Only you can see and use this resource',
            self::Organization => 'All members of your organization can see and use this resource',
        };
    }
}
