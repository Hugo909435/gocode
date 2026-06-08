<?php

namespace App\Agent;

enum AgentEventType: string
{
    case Status              = 'status';
    case Plan                = 'plan';
    case Message             = 'message';
    case Log                 = 'log';
    case Terminal            = 'terminal';
    case ToolCall            = 'tool_call';
    case FileChange          = 'file_change';
    case ConfirmationRequest = 'confirmation_request';
    case Cost                = 'cost';
    case Done                = 'done';
    case Error               = 'error';
}
