<?php

namespace App\Services;

use App\Enums\ToolType;
use App\Models\Tool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool as PrismTool;

class ToolBuilderService
{
    public function __construct(
        private readonly ToolExecutionService $executionService,
        private readonly ToolConfigService $configService,
    ) {}

    /**
     * Build Prism Tool objects from a collection of database Tool models.
     *
     * @param  Collection<int, Tool>  $tools
     * @return array<PrismTool>
     */
    public function buildPrismTools(Collection $tools): array
    {
        return $tools
            ->filter(fn (Tool $tool) => $this->isExecutable($tool))
            ->map(fn (Tool $tool) => $this->buildPrismTool($tool))
            ->values()
            ->all();
    }

    /**
     * Check if a tool type is executable.
     */
    private function isExecutable(Tool $tool): bool
    {
        return in_array($tool->type, [
            ToolType::Database,
            ToolType::RestApi,
            ToolType::Graphql,
        ]);
    }

    /**
     * Build a single Prism Tool from a database Tool model.
     */
    private function buildPrismTool(Tool $dbTool): PrismTool
    {
        $prismTool = new PrismTool;

        // Set name and description
        $prismTool
            ->as($this->sanitizeName($dbTool->name))
            ->for($dbTool->description ?? "Execute {$dbTool->name}");

        // Add parameters based on tool type
        $this->addParametersForType($prismTool, $dbTool);

        // Set up execution handler
        $prismTool->using(function (...$args) use ($dbTool) {
            return $this->executeTool($dbTool, $args);
        });

        // Set up error handler
        $prismTool->failed(function (\Throwable $e, array $params) use ($dbTool) {
            Log::error('Tool execution failed', [
                'tool_id' => $dbTool->id,
                'tool_name' => $dbTool->name,
                'error' => $e->getMessage(),
                'params' => array_keys($params),
            ]);

            return "Tool '{$dbTool->name}' failed: {$e->getMessage()}";
        });

        return $prismTool;
    }

    /**
     * Sanitize tool name for LLM compatibility.
     * Tool names must be alphanumeric with underscores.
     */
    private function sanitizeName(string $name): string
    {
        // Replace spaces and hyphens with underscores
        $sanitized = preg_replace('/[\s\-]+/', '_', $name);

        // Remove any non-alphanumeric characters (except underscores)
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $sanitized);

        // Ensure it starts with a letter
        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 'tool_'.$sanitized;
        }

        // Convert to lowercase
        return strtolower($sanitized);
    }

    /**
     * Add parameters to the Prism tool based on tool type.
     */
    private function addParametersForType(PrismTool $prismTool, Tool $dbTool): void
    {
        $config = $dbTool->config ?? [];

        match ($dbTool->type) {
            ToolType::Database => $this->addDatabaseParameters($prismTool, $config),
            ToolType::RestApi => $this->addRestApiParameters($prismTool, $config),
            ToolType::Graphql => $this->addGraphqlParameters($prismTool, $config),
            default => null,
        };
    }

    /**
     * Extract and add parameters from database query template.
     * Named parameters use :param_name syntax.
     */
    private function addDatabaseParameters(PrismTool $prismTool, array $config): void
    {
        $query = $config['query_template'] ?? '';

        // Extract named parameters (:param_name)
        preg_match_all('/\:([a-zA-Z_][a-zA-Z0-9_]*)/', $query, $matches);

        $paramNames = array_unique($matches[1] ?? []);

        foreach ($paramNames as $paramName) {
            $prismTool->withStringParameter(
                $paramName,
                "Value for the '{$paramName}' parameter in the SQL query"
            );
        }
    }

    /**
     * Extract and add parameters from REST API configuration.
     * Path/body parameters use {{param_name}} syntax.
     */
    private function addRestApiParameters(PrismTool $prismTool, array $config): void
    {
        $params = [];

        // Extract from endpoint path
        $endpoint = $config['endpoint'] ?? '';
        preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $endpoint, $pathMatches);
        $params = array_merge($params, $pathMatches[1] ?? []);

        // Extract from request body template
        $body = $config['body_template'] ?? '';
        if (is_string($body)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $body, $bodyMatches);
            $params = array_merge($params, $bodyMatches[1] ?? []);
        }

        // Extract from query parameters template (if JSON string)
        $queryParams = $config['query_params'] ?? '';
        if (is_string($queryParams)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $queryParams, $queryMatches);
            $params = array_merge($params, $queryMatches[1] ?? []);
        }

        $params = array_unique($params);

        foreach ($params as $paramName) {
            $prismTool->withStringParameter(
                $paramName,
                "Value for the '{$paramName}' parameter"
            );
        }
    }

    /**
     * Extract and add parameters from GraphQL configuration.
     * Variables use {{param_name}} syntax.
     */
    private function addGraphqlParameters(PrismTool $prismTool, array $config): void
    {
        $params = [];

        // Extract from variables template
        $variables = $config['variables_template'] ?? '';
        if (is_string($variables)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $variables, $matches);
            $params = array_merge($params, $matches[1] ?? []);
        }

        // Also check the query itself for variable references
        $query = $config['query'] ?? '';
        if (is_string($query)) {
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $query, $queryMatches);
            $params = array_merge($params, $queryMatches[1] ?? []);
        }

        $params = array_unique($params);

        foreach ($params as $paramName) {
            $prismTool->withStringParameter(
                $paramName,
                "Value for the '{$paramName}' GraphQL variable"
            );
        }
    }

    /**
     * Execute the tool and return the result as a string for the LLM.
     *
     * @param  array<int|string, mixed>  $args
     */
    private function executeTool(Tool $tool, array $args): string
    {
        // Convert positional args to named parameters
        $parameters = $this->extractParameters($args);

        Log::info('Executing tool via LLM', [
            'tool_id' => $tool->id,
            'tool_name' => $tool->name,
            'parameters' => array_keys($parameters),
        ]);

        // Reload the tool to get fresh data
        $tool = $tool->fresh();

        $result = $this->executionService->execute($tool, $parameters);

        if (! $result->success) {
            return "Error executing {$tool->name}: {$result->error}";
        }

        // Format the result for the LLM
        return $this->formatResultForLLM($tool, $result->data, $result->metadata);
    }

    /**
     * Extract parameters from args array.
     * Handles both positional and named arguments.
     *
     * @param  array<int|string, mixed>  $args
     * @return array<string, mixed>
     */
    private function extractParameters(array $args): array
    {
        // Prism passes named parameters as an associative array
        if (! array_is_list($args)) {
            return $args;
        }

        // If positional, try to get from first element if it's an array
        if (count($args) === 1 && is_array($args[0])) {
            return $args[0];
        }

        return $args;
    }

    /**
     * Format execution result as a string for the LLM.
     */
    private function formatResultForLLM(Tool $tool, mixed $data, array $metadata = []): string
    {
        if ($data === null) {
            return "Tool '{$tool->name}' executed successfully with no data returned.";
        }

        // For database queries, format as a table or list
        if ($tool->type === ToolType::Database && is_array($data)) {
            return $this->formatDatabaseResult($data, $metadata);
        }

        // For API responses, format as JSON
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

        // For small result sets, show as formatted JSON
        if ($rowCount <= 10) {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $result = "Query returned {$rowCount} row(s):\n```json\n{$json}\n```";
        } else {
            // For larger sets, show summary and first few rows
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
