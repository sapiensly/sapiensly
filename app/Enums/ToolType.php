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

    /**
     * Tool types accepted when creating a tool. `function` and `graphql` are
     * disabled — they stay in the enum so existing tools keep resolving, but
     * can no longer be created. (`group` is still creatable via the route; it's
     * only hidden from the UI picker — see ToolController::selectableToolTypes.)
     *
     * @return array<int, self>
     */
    public static function creatable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => ! in_array($type, [self::Function, self::Graphql], true),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Function => __('Function'),
            self::Mcp => __('MCP Server'),
            self::Group => __('Tool Group'),
            self::RestApi => __('REST API'),
            self::Graphql => __('GraphQL'),
            self::Database => __('Database'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Function => __('Custom function with JSON schema definition'),
            self::Mcp => __('Model Context Protocol server integration'),
            self::Group => __('Collection of multiple tools'),
            self::RestApi => __('HTTP REST API integration with configurable endpoints'),
            self::Graphql => __('GraphQL API with query and mutation support'),
            self::Database => __('Direct database query execution with safety controls'),
        };
    }
}
