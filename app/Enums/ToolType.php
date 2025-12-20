<?php

namespace App\Enums;

enum ToolType: string
{
    case Function = 'function';
    case Mcp = 'mcp';
    case Group = 'group';
    case RestApi = 'rest_api';
    case Graphql = 'graphql';
    case Database = 'database';

    public function label(): string
    {
        return match ($this) {
            self::Function => 'Function',
            self::Mcp => 'MCP Server',
            self::Group => 'Tool Group',
            self::RestApi => 'REST API',
            self::Graphql => 'GraphQL',
            self::Database => 'Database',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Function => 'Custom function with JSON schema definition',
            self::Mcp => 'Model Context Protocol server integration',
            self::Group => 'Collection of multiple tools',
            self::RestApi => 'HTTP REST API integration with configurable endpoints',
            self::Graphql => 'GraphQL API with query and mutation support',
            self::Database => 'Direct database query execution with safety controls',
        };
    }
}
