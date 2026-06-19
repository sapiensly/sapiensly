<?php

namespace App\Enums;

enum BotFlowActionType: string
{
    case ShowMenu = 'show_menu';
    case SendMessage = 'send_message';
    case AgentHandoff = 'agent_handoff';
    case End = 'end';
    case AwaitLlmClassification = 'await_llm_classification';
}
