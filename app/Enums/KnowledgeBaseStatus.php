<?php

namespace App\Enums;

enum KnowledgeBaseStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Processing => __('Processing'),
            self::Ready => __('Ready'),
            self::Failed => __('Failed'),
        };
    }
}
