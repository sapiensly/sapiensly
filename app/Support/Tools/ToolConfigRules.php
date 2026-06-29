<?php

namespace App\Support\Tools;

use App\Models\User;
use App\Rules\AccessibleIntegration;
use App\Rules\AccessibleOAuth2Integration;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for the per-type `config.*` validation rules of a
 * Tool. Shared by the web Store/Update form requests and the MCP
 * create_tool/update_tool tools so both accept exactly the same config shapes.
 */
class ToolConfigRules
{
    /**
     * The `config.*` validation rules for a given tool type. Returns an empty
     * array for types without a structured config (e.g. group).
     *
     * @return array<string, mixed>
     */
    public static function forType(?string $type, ?User $user = null): array
    {
        return match ($type) {
            'function' => self::function(),
            'mcp' => self::mcp($user),
            'rest_api' => self::restApi($user),
            'graphql' => self::graphql($user),
            'database' => self::database(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function function(): array
    {
        return [
            'config.name' => ['nullable', 'string', 'max:255'],
            'config.description' => ['nullable', 'string', 'max:1000'],
            'config.parameters' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mcp(?User $user): array
    {
        return [
            'config.endpoint' => ['required', 'string', 'url', 'max:500'],
            'config.auth_type' => ['required', 'string', Rule::in(['none', 'bearer', 'api_key', 'basic', 'oauth2'])],
            'config.auth_config' => ['nullable', 'array'],
            'config.integration_id' => [
                'nullable',
                'required_if:config.auth_type,oauth2',
                'string',
                new AccessibleOAuth2Integration($user),
            ],
        ];
    }

    /**
     * A connected tool borrows base URL + auth from its Connection
     * (integration), so those become optional; a legacy self-contained tool
     * still supplies them inline. Exactly one mode applies per tool.
     *
     * @return array<string, mixed>
     */
    private static function restApi(?User $user): array
    {
        return [
            'config.integration_id' => ['nullable', 'string', new AccessibleIntegration($user)],
            'config.base_url' => ['required_without:config.integration_id', 'nullable', 'string', 'url', 'max:500'],
            'config.method' => ['required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'config.path' => ['nullable', 'string', 'max:500'],
            'config.headers' => ['nullable', 'array'],
            'config.auth_type' => ['required_without:config.integration_id', 'nullable', 'string', Rule::in(['none', 'bearer', 'api_key', 'basic', 'oauth2'])],
            'config.auth_config' => ['nullable', 'array'],
            'config.request_body_template' => ['nullable', 'string'],
            'config.response_mapping' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function graphql(?User $user): array
    {
        return [
            'config.integration_id' => ['nullable', 'string', new AccessibleIntegration($user)],
            'config.endpoint' => ['required_without:config.integration_id', 'nullable', 'string', 'url', 'max:500'],
            'config.operation_type' => ['required', 'string', Rule::in(['query', 'mutation'])],
            'config.operation' => ['required', 'string', 'max:10000'],
            'config.variables_template' => ['nullable', 'array'],
            'config.auth_type' => ['nullable', 'string', Rule::in(['none', 'bearer', 'api_key'])],
            'config.auth_config' => ['nullable', 'array'],
            'config.response_mapping' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function database(): array
    {
        return [
            'config.driver' => ['required', 'string', Rule::in(['pgsql', 'mysql', 'sqlite', 'sqlsrv'])],
            'config.host' => ['required_unless:config.driver,sqlite', 'nullable', 'string', 'max:255'],
            'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'config.database' => ['required', 'string', 'max:255'],
            'config.username' => ['required_unless:config.driver,sqlite', 'nullable', 'string', 'max:255'],
            'config.password' => ['nullable', 'string'],
            'config.query_template' => ['required', 'string', 'max:10000'],
            'config.read_only' => ['nullable', 'boolean'],
        ];
    }
}
