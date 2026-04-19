<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Pending = 'pending';
    case Open = 'open';
    case Resolved = 'resolved';
    case Escalated = 'escalated';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Open => __('Open'),
            self::Resolved => __('Resolved'),
            self::Escalated => __('Escalated'),
            self::Abandoned => __('Abandoned'),
        };
    }

    /**
     * When escalated, automated replies are suppressed in favour of human takeover.
     */
    public function suppressesAutoReply(): bool
    {
        return $this === self::Escalated || $this === self::Resolved || $this === self::Abandoned;
    }
}
