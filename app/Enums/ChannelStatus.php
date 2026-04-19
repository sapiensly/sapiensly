<?php

namespace App\Enums;

enum ChannelStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Active'),
            self::Paused => __('Paused'),
            self::Suspended => __('Suspended'),
        };
    }

    public function isDeliverable(): bool
    {
        return $this === self::Active;
    }
}
