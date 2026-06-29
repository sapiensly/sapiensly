<?php

namespace App\Enums;

/**
 * What kind of external system a Connection (Integration) reaches.
 *
 * `http` and `mcp` use the HTTP machinery (base URL, auth strategies, saved
 * requests, environments, SSRF guard). `database` is a DSN-shaped connection to
 * an external database the agent queries to analyse/transform its data — it
 * carries its credentials in `auth_config` and uses none of the HTTP features.
 */
enum IntegrationKind: string
{
    case Http = 'http';
    case Mcp = 'mcp';
    case Database = 'database';

    public function label(): string
    {
        return match ($this) {
            self::Http => __('REST / HTTP API'),
            self::Mcp => __('MCP Server'),
            self::Database => __('Database'),
        };
    }

    /**
     * Whether this kind uses the HTTP request/auth machinery.
     */
    public function isHttp(): bool
    {
        return $this === self::Http || $this === self::Mcp;
    }
}
