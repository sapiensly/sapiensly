<?php

namespace App\Enums;

enum Visibility: string
{
    case Private = 'private';
    case Organization = 'organization';
    case Global = 'global';

    public function label(): string
    {
        return match ($this) {
            self::Private => __('Private'),
            self::Organization => __('Organization'),
            self::Global => __('Global'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => __('Only you can see and use this resource'),
            self::Organization => __('All members of your organization can see and use this resource'),
            self::Global => __('Available to every workspace across the platform'),
        };
    }
}
