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
            self::Triage => 'Triage Agent',
            self::Knowledge => 'Knowledge Agent',
            self::Action => 'Action Agent',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Triage => 'Classifies intent, urgency, and sentiment',
            self::Knowledge => 'Searches company documentation with RAG',
            self::Action => 'Executes real-world operations via tools',
        };
    }
}
