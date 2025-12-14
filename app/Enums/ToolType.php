<?php

namespace App\Enums;

enum ToolType: string
{
    case Function = 'function';
    case Mcp = 'mcp';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Function => 'Function',
            self::Mcp => 'MCP Server',
            self::Group => 'Tool Group',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Function => 'Custom function with JSON schema definition',
            self::Mcp => 'Model Context Protocol server integration',
            self::Group => 'Collection of multiple tools',
        };
    }
}
