<?php

namespace App\Ai\Tools;

use App\Enums\ToolType;
use App\Models\Tool;
use App\Services\ToolExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request;

class DynamicTool implements ToolContract
{
    public function __construct(
        private Tool $dbTool,
        private ToolExecutionService $executionService,
    ) {}

    /**
     * Get the sanitized tool name for LLM compatibility.
     */
    public function name(): string
    {
        return self::sanitizeName($this->dbTool->name);
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return $this->dbTool->description ?? "Execute {$this->dbTool->name}";
    }

    /**
     * Define the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        $config = $this->dbTool->config ?? [];

        return match ($this->dbTool->type) {
            ToolType::Database => $this->databaseSchema($schema, $config),
            ToolType::RestApi => $this->restApiSchema($schema, $config),
            ToolType::Graphql => $this->graphqlSchema($schema, $config),
            default => [],
        };
    }

    /**
     * Execute the tool with the given request.
     */
    public function handle(Request $request): string
    {
        $parameters = $request->all();

        Log::info('Executing tool via LLM', [
            'tool_id' => $this->dbTool->id,
            'tool_name' => $this->dbTool->name,
            'parameters' => array_keys($parameters),
        ]);

        $tool = $this->dbTool->fresh();

        try {
            $result = $this->executionService->execute($tool, $parameters);

            if (! $result->success) {
                return "Error executing {$tool->name}: {$result->error}";
            }

            return $this->formatResultForLLM($tool, $result->data, $result->metadata);
        } catch (\Throwable $e) {
            Log::error('Tool execution failed', [
                'tool_id' => $this->dbTool->id,
                'tool_name' => $this->dbTool->name,
                'error' => $e->getMessage(),
                'params' => array_keys($parameters),
            ]);

            return "Tool '{$this->dbTool->name}' failed: {$e->getMessage()}";
        }
    }

    /**
     * Sanitize tool name for LLM compatibility.
     */
    public static function sanitizeName(string $name): string
    {
        $sanitized = preg_replace('/[\s\-]+/', '_', $name);
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $sanitized);

        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 'tool_'.$sanitized;
        }

        return strtolower($sanitized);
    }

    /**
     * Extract parameters from database query template.
     */
    private function databaseSchema(JsonSchema $schema, array $config): array
    {
        $query = $config['query_template'] ?? '';
        preg_match_all('/\:([a-zA-Z_][a-zA-Z0-9_]*)/', $query, $matches);

        $params = [];
        foreach (array_unique($matches[1] ?? []) as $paramName) {
            $params[$paramName] = $schema
                ->string()
                ->description("Value for the '{$paramName}' parameter in the SQL query");
        }

        return $params;
    }

    /**
     * Extract parameters from REST API configuration.
     */
    private function restApiSchema(JsonSchema $schema, array $config): array
    {
        $paramNames = [];

        $endpoint = $config['endpoint'] ?? '';
        preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $endpoint, $pathMatches);
        $paramNames = array_merge($paramNames, $pathMatches[1] ?? []);

        $body = $config['body_template'] ?? '';
        if (is_string($body)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $body, $bodyMatches);
            $paramNames = array_merge($paramNames, $bodyMatches[1] ?? []);
        }

        $queryParams = $config['query_params'] ?? '';
        if (is_string($queryParams)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $queryParams, $queryMatches);
            $paramNames = array_merge($paramNames, $queryMatches[1] ?? []);
        }

        $params = [];
        foreach (array_unique($paramNames) as $paramName) {
            $params[$paramName] = $schema
                ->string()
                ->description("Value for the '{$paramName}' parameter");
        }

        return $params;
    }

    /**
     * Extract parameters from GraphQL configuration.
     */
    private function graphqlSchema(JsonSchema $schema, array $config): array
    {
        $paramNames = [];

        $variables = $config['variables_template'] ?? '';
        if (is_string($variables)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $variables, $matches);
            $paramNames = array_merge($paramNames, $matches[1] ?? []);
        }

        $query = $config['query'] ?? '';
        if (is_string($query)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $query, $queryMatches);
            $paramNames = array_merge($paramNames, $queryMatches[1] ?? []);
        }

        $params = [];
        foreach (array_unique($paramNames) as $paramName) {
            $params[$paramName] = $schema
                ->string()
                ->description("Value for the '{$paramName}' GraphQL variable");
        }

        return $params;
    }

    /**
     * Format execution result as a string for the LLM.
     */
    private function formatResultForLLM(Tool $tool, mixed $data, array $metadata = []): string
    {
        if ($data === null) {
            return "Tool '{$tool->name}' executed successfully with no data returned.";
        }

        if ($tool->type === ToolType::Database && is_array($data)) {
            return $this->formatDatabaseResult($data, $metadata);
        }

        if (is_array($data) || is_object($data)) {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return "Result:\n```json\n{$json}\n```";
        }

        return "Result: {$data}";
    }

    /**
     * Format database query results for the LLM.
     */
    private function formatDatabaseResult(array $data, array $metadata = []): string
    {
        $rowCount = $metadata['row_count'] ?? count($data);
        $truncated = $metadata['truncated'] ?? false;

        if (empty($data)) {
            return 'Query returned no results.';
        }

        if ($rowCount <= 10) {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $result = "Query returned {$rowCount} row(s):\n```json\n{$json}\n```";
        } else {
            $sample = array_slice($data, 0, 5);
            $json = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $result = "Query returned {$rowCount} row(s). First 5 rows:\n```json\n{$json}\n```";
        }

        if ($truncated) {
            $totalCount = $metadata['total_count'] ?? $rowCount;
            $result .= "\n(Results truncated. Total: {$totalCount} rows)";
        }

        return $result;
    }
}
