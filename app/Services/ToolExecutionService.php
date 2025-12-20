<?php

namespace App\Services;

use App\Contracts\ToolExecutor;
use App\DTOs\ToolExecutionResult;
use App\Enums\ToolType;
use App\Models\Tool;
use App\Services\Tools\DatabaseExecutor;
use App\Services\Tools\GraphqlExecutor;
use App\Services\Tools\RestApiExecutor;
use Illuminate\Support\Facades\Log;

class ToolExecutionService
{
    private array $executors = [];

    public function __construct(
        private readonly ToolConfigService $configService,
        RestApiExecutor $restApiExecutor,
        GraphqlExecutor $graphqlExecutor,
        DatabaseExecutor $databaseExecutor,
    ) {
        $this->executors = [
            ToolType::RestApi->value => $restApiExecutor,
            ToolType::Graphql->value => $graphqlExecutor,
            ToolType::Database->value => $databaseExecutor,
        ];
    }

    /**
     * Execute a tool with the given parameters.
     *
     * @param  array<string, mixed>  $parameters  The parameters to pass to the tool
     */
    public function execute(Tool $tool, array $parameters = []): ToolExecutionResult
    {
        $startTime = microtime(true);

        // Check if tool is active
        if ($tool->status->value !== 'active') {
            return ToolExecutionResult::failure(
                error: "Tool '{$tool->name}' is not active. Current status: {$tool->status->value}",
            );
        }

        // Get the executor for this tool type
        $executor = $this->getExecutor($tool->type);

        if ($executor === null) {
            return ToolExecutionResult::failure(
                error: "No executor available for tool type: {$tool->type->value}",
            );
        }

        // Decrypt sensitive configuration
        $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);

        // Validate the tool configuration and parameters
        $validationErrors = $executor->validate($tool, $parameters, $config);

        if (! empty($validationErrors)) {
            return ToolExecutionResult::failure(
                error: 'Validation failed: '.json_encode($validationErrors),
                metadata: ['validation_errors' => $validationErrors],
            );
        }

        // Execute the tool
        Log::info('Executing tool', [
            'tool_id' => $tool->id,
            'tool_name' => $tool->name,
            'tool_type' => $tool->type->value,
            'parameters' => array_keys($parameters),
        ]);

        $result = $executor->execute($tool, $parameters, $config);

        Log::info('Tool execution completed', [
            'tool_id' => $tool->id,
            'tool_name' => $tool->name,
            'success' => $result->success,
            'execution_time_ms' => $result->executionTimeMs,
        ]);

        return $result;
    }

    /**
     * Execute a tool group (runs all tools in the group).
     *
     * @return array<string, ToolExecutionResult>
     */
    public function executeGroup(Tool $groupTool, array $parameters = []): array
    {
        if ($groupTool->type !== ToolType::Group) {
            return [
                'error' => ToolExecutionResult::failure(
                    error: 'Tool is not a group tool',
                ),
            ];
        }

        $results = [];

        // Load group items with their tools
        $groupTool->load(['groupItems.tool']);

        foreach ($groupTool->groupItems as $item) {
            if ($item->tool && $item->tool->status->value === 'active') {
                $results[$item->tool->id] = $this->execute($item->tool, $parameters);
            }
        }

        return $results;
    }

    /**
     * Validate a tool's configuration without executing it.
     *
     * @return array<string, string> Validation errors (empty if valid)
     */
    public function validate(Tool $tool, array $parameters = []): array
    {
        $executor = $this->getExecutor($tool->type);

        if ($executor === null) {
            return ['type' => "No executor available for tool type: {$tool->type->value}"];
        }

        $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);

        return $executor->validate($tool, $parameters, $config);
    }

    /**
     * Test a tool's connection/configuration.
     */
    public function testConnection(Tool $tool): ToolExecutionResult
    {
        // For database tools, we can test the connection
        if ($tool->type === ToolType::Database) {
            return $this->testDatabaseConnection($tool);
        }

        // For REST API tools, we could do a HEAD request
        if ($tool->type === ToolType::RestApi) {
            return $this->testRestApiConnection($tool);
        }

        // For GraphQL, we could do an introspection query
        if ($tool->type === ToolType::Graphql) {
            return $this->testGraphqlConnection($tool);
        }

        return ToolExecutionResult::failure(
            error: "Connection test not supported for tool type: {$tool->type->value}",
        );
    }

    /**
     * Check if a tool type is supported for execution.
     */
    public function isExecutable(ToolType $type): bool
    {
        return isset($this->executors[$type->value]);
    }

    /**
     * Get the executor for a tool type.
     */
    private function getExecutor(ToolType $type): ?ToolExecutor
    {
        return $this->executors[$type->value] ?? null;
    }

    private function testDatabaseConnection(Tool $tool): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);

            // Create a simple SELECT 1 query to test connection
            $testTool = clone $tool;
            $testConfig = array_merge($config, ['query_template' => 'SELECT 1 as test']);

            $executor = $this->executors[ToolType::Database->value];
            $result = $executor->execute($testTool, [], $testConfig);

            if ($result->success) {
                return ToolExecutionResult::success(
                    data: ['message' => 'Database connection successful'],
                    metadata: ['driver' => $config['driver'] ?? 'unknown'],
                    executionTimeMs: (microtime(true) - $startTime) * 1000,
                );
            }

            return $result;
        } catch (\Exception $e) {
            return ToolExecutionResult::failure(
                error: 'Connection test failed: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        }
    }

    private function testRestApiConnection(Tool $tool): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);
            $baseUrl = $config['base_url'] ?? '';

            if (empty($baseUrl)) {
                return ToolExecutionResult::failure(
                    error: 'Base URL is not configured',
                );
            }

            // Try a HEAD request to the base URL
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->head($baseUrl);

            return ToolExecutionResult::success(
                data: [
                    'message' => 'API endpoint reachable',
                    'status_code' => $response->status(),
                ],
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } catch (\Exception $e) {
            return ToolExecutionResult::failure(
                error: 'Connection test failed: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        }
    }

    private function testGraphqlConnection(Tool $tool): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);
            $endpoint = $config['endpoint'] ?? '';

            if (empty($endpoint)) {
                return ToolExecutionResult::failure(
                    error: 'GraphQL endpoint is not configured',
                );
            }

            // Try an introspection query
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->post($endpoint, [
                    'query' => '{ __typename }',
                ]);

            if ($response->successful()) {
                return ToolExecutionResult::success(
                    data: ['message' => 'GraphQL endpoint reachable'],
                    executionTimeMs: (microtime(true) - $startTime) * 1000,
                );
            }

            return ToolExecutionResult::failure(
                error: "GraphQL endpoint returned status: {$response->status()}",
                statusCode: $response->status(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } catch (\Exception $e) {
            return ToolExecutionResult::failure(
                error: 'Connection test failed: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        }
    }
}
