<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Tools\McpClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request;

/**
 * Exposes a single tool from an MCP server to the chat agent. The tool's
 * schema comes from the server's tools/list (cached on the DB Tool); calling
 * it issues a tools/call over MCP as the current user (so OAuth tokens apply).
 */
class McpServerTool implements ToolContract
{
    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>}  $definition
     * @param  array<string, mixed>  $serverConfig  Decrypted MCP tool config (endpoint, auth, integration_id).
     */
    public function __construct(
        private readonly array $definition,
        private readonly array $serverConfig,
        private readonly ?User $user,
        private readonly McpClient $client,
    ) {}

    public function name(): string
    {
        return DynamicTool::sanitizeName($this->definition['name'] ?? 'mcp_tool');
    }

    public function description(): string
    {
        return $this->definition['description'] ?: ('MCP tool: '.($this->definition['name'] ?? ''));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $input = $this->definition['input_schema'] ?? [];
        $properties = is_array($input['properties'] ?? null) ? $input['properties'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];

        $out = [];
        foreach ($properties as $key => $prop) {
            if (! is_string($key) || ! is_array($prop)) {
                continue;
            }
            $type = $this->mapType($schema, $prop);
            if (! empty($prop['description'])) {
                $type = $type->description((string) $prop['description']);
            }
            if (in_array($key, $required, true)) {
                $type = $type->required();
            }
            $out[$key] = $type;
        }

        return $out;
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        try {
            return $this->client->callTool($this->serverConfig, $this->user, (string) $this->definition['name'], $args);
        } catch (\Throwable $e) {
            Log::warning('MCP tool call failed', [
                'tool' => $this->definition['name'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return "MCP tool '{$this->definition['name']}' failed: {$e->getMessage()}";
        }
    }

    /**
     * @param  array<string, mixed>  $prop
     */
    private function mapType(JsonSchema $schema, array $prop): Type
    {
        return match ((string) ($prop['type'] ?? 'string')) {
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            'boolean' => $schema->boolean(),
            'array' => $schema->array(),
            'object' => $schema->object(),
            default => $schema->string(),
        };
    }
}
