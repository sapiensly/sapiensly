<?php

namespace App\Enums;

enum FlowActionType: string
{
    case ShowMenu = 'show_menu';
    case SendMessage = 'send_message';
    case AgentHandoff = 'agent_handoff';
    case End = 'end';
    case AwaitLlmClassification = 'await_llm_classification';
}
