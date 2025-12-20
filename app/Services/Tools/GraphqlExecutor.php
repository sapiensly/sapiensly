<?php

namespace App\Services\Tools;

use App\Contracts\ToolExecutor;
use App\DTOs\ToolExecutionResult;
use App\Models\Tool;
use App\Services\Tools\Concerns\SubstitutesParameters;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GraphqlExecutor implements ToolExecutor
{
    use SubstitutesParameters;

    private const TIMEOUT_SECONDS = 30;

    public function execute(Tool $tool, array $parameters, array $config): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $endpoint = $config['endpoint'] ?? '';
            $operation = $config['operation'] ?? '';
            $variables = $this->buildVariables($config, $parameters);

            $request = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]);

            // Add authentication
            $request = $this->applyAuthentication($request, $config);

            // Execute GraphQL request
            $response = $request->post($endpoint, [
                'query' => $operation,
                'variables' => $variables,
            ]);

            $executionTimeMs = (microtime(true) - $startTime) * 1000;
            $body = $response->json();

            // Check for GraphQL errors
            if (isset($body['errors']) && ! empty($body['errors'])) {
                $errorMessages = collect($body['errors'])
                    ->pluck('message')
                    ->implode('; ');

                return ToolExecutionResult::failure(
                    error: 'GraphQL errors: '.$errorMessages,
                    statusCode: $response->status(),
                    metadata: [
                        'endpoint' => $endpoint,
                        'operation_type' => $config['operation_type'] ?? 'query',
                        'errors' => $body['errors'],
                    ],
                    executionTimeMs: $executionTimeMs,
                );
            }

            if (! $response->successful()) {
                return ToolExecutionResult::failure(
                    error: "HTTP {$response->status()}: ".$response->body(),
                    statusCode: $response->status(),
                    metadata: ['endpoint' => $endpoint],
                    executionTimeMs: $executionTimeMs,
                );
            }

            $data = $body['data'] ?? $body;
            $data = $this->mapResponse($data, $config);

            return ToolExecutionResult::success(
                data: $data,
                metadata: [
                    'endpoint' => $endpoint,
                    'operation_type' => $config['operation_type'] ?? 'query',
                ],
                executionTimeMs: $executionTimeMs,
            );
        } catch (ConnectionException $e) {
            Log::warning('GraphQL tool connection failed', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Connection failed: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } catch (\Exception $e) {
            Log::error('GraphQL tool execution error', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Execution error: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        }
    }

    public function validate(Tool $tool, array $parameters, array $config): array
    {
        $errors = [];

        if (empty($config['endpoint'])) {
            $errors['endpoint'] = 'GraphQL endpoint is required';
        }

        if (empty($config['operation'])) {
            $errors['operation'] = 'GraphQL operation is required';
        }

        if (! empty($config['auth_type']) && $config['auth_type'] !== 'none') {
            if (empty($config['auth_config'])) {
                $errors['auth_config'] = 'Authentication configuration is required';
            }
        }

        return $errors;
    }

    private function buildVariables(array $config, array $parameters): array
    {
        // If a variables template is defined, use it
        if (! empty($config['variables_template'])) {
            return $this->substituteArray($config['variables_template'], $parameters);
        }

        // Otherwise, pass parameters directly as variables
        return $parameters;
    }

    private function applyAuthentication($request, array $config)
    {
        $authType = $config['auth_type'] ?? 'none';
        $authConfig = $config['auth_config'] ?? [];

        return match ($authType) {
            'bearer' => $request->withToken($authConfig['token'] ?? ''),
            'api_key' => $request->withHeaders([
                $authConfig['header_name'] ?? 'X-API-Key' => $authConfig['key'] ?? '',
            ]),
            default => $request,
        };
    }

    private function mapResponse(mixed $data, array $config): mixed
    {
        if (empty($config['response_mapping']) || ! is_array($data)) {
            return $data;
        }

        $mapping = $config['response_mapping'];
        $result = [];

        foreach ($mapping as $targetKey => $sourcePath) {
            $result[$targetKey] = data_get($data, $sourcePath);
        }

        return $result;
    }
}
