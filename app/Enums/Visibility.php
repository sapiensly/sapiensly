<?php

namespace App\Enums;

enum Visibility: string
{
    case Private = 'private';
    case Organization = 'organization';
    case Global = 'global';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => __('Private'),
            self::Organization => __('Organization'),
            self::Global => __('Global'),
            self::Public => __('Public'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => __('Only you can see and use this resource'),
            self::Organization => __('All members of your organization can see and use this resource'),
            self::Global => __('Available to every workspace across the platform'),
            self::Public => __('Anyone with the link can view this resource without signing in'),
        };
    }
}
