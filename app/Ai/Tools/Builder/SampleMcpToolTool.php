<?php

namespace App\Ai\Tools\Builder;

use App\Models\Integration;
use App\Models\User;
use App\Services\Tools\McpClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Builder power for MCP integrations. `sample_endpoint` only speaks REST — it
 * hits an MCP server with GET and gets a 405, so the builder had no way to see
 * or use what an MCP connection exposes and fell back to inventing demo data.
 * This tool speaks the protocol: with no tool_name it LISTS the server's tools
 * (discovery); with a tool_name it CALLS it and returns the result — real data
 * the builder can model an object from and seed with. Runs as the acting user,
 * so a per-user-authorized OAuth server sees that member's token.
 */
class SampleMcpToolTool implements Tool
{
    public function __construct(
        private McpClient $client,
        private User $user,
    ) {}

    public function name(): string
    {
        return 'sample_mcp_tool';
    }

    public function description(): string
    {
        return <<<'DESC'
Discover and call the tools of an MCP integration to pull REAL data. Provide
integration_id (an is_mcp connection from list_available_integrations). With no
tool_name it returns the server's tools ({name, description, input_schema}) —
call this FIRST to see what operations exist. With tool_name (+ optional
arguments object) it calls that tool and returns its result as text — use it to
fetch actual records, then model an object from their shape and seed_records the
real rows (never invent demo data when a live MCP source is connected). Read a
tool's input_schema before calling it. Runs as you; a per-user-authorized OAuth
server sees your token (authorize the connection first if it isn't).
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('The MCP integration id (an is_mcp connection from list_available_integrations).')
                ->required(),
            'tool_name' => $schema->string()
                ->description('The MCP tool to call. Omit to list the server\'s available tools first.'),
            'arguments' => $schema->object()
                ->description('Arguments for the MCP tool call, matching its input_schema. Omit for a no-arg tool or when listing.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $integration = Integration::query()
            ->forAccountContext($this->user)
            ->find((string) ($args['integration_id'] ?? ''));

        if (! $integration instanceof Integration) {
            return json_encode(['ok' => false, 'error' => 'Integration not found for this tenant.'], JSON_THROW_ON_ERROR);
        }
        if (! $integration->is_mcp) {
            return json_encode(['ok' => false, 'error' => 'This integration is not an MCP server — use sample_endpoint for REST connections.'], JSON_THROW_ON_ERROR);
        }

        $config = [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            // MCP tool configs express OAuth connections as the 'oauth2' scheme;
            // McpAuthResolver then resolves the acting user's token via the
            // integration_id. Static schemes pass through with their auth_config.
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];

        $toolName = trim((string) ($args['tool_name'] ?? ''));

        try {
            if ($toolName === '') {
                $tools = $this->client->listTools($config, $this->user);

                return json_encode(['ok' => true, 'tools' => $tools], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $arguments = is_array($args['arguments'] ?? null) ? $args['arguments'] : [];
            $result = $this->client->callTool($config, $this->user, $toolName, $arguments);

            return json_encode([
                'ok' => true,
                'tool_name' => $toolName,
                'result' => Str::limit($result, 8000),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
    }
}
