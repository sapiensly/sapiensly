<?php

namespace App\Enums;

enum ChannelType: string
{
    case Widget = 'widget';
    case WhatsApp = 'whatsapp';
    // Reserved for later: Telegram, Sms, Email — the schema already supports them.

    public function label(): string
    {
        return match ($this) {
            self::Widget => __('Web chat widget'),
            self::WhatsApp => __('WhatsApp'),
        };
    }
}
