<?php

namespace App\Enums;

enum AgentType: string
{
    case Triage = 'triage';
    case Knowledge = 'knowledge';
    case Action = 'action';

    public function label(): string
    {
        return match ($this) {
            self::Triage => __('Triage Agent'),
            self::Knowledge => __('Knowledge Agent'),
            self::Action => __('Action Agent'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Triage => __('Classifies intent, urgency, and sentiment'),
            self::Knowledge => __('Searches company documentation with RAG'),
            self::Action => __('Executes real-world operations via tools'),
        };
    }
}
