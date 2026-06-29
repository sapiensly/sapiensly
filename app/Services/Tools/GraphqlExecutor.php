<?php

namespace App\Services\Tools;

use App\Contracts\ToolExecutor;
use App\DTOs\ToolExecutionResult;
use App\Models\Integration;
use App\Models\Tool;
use App\Services\Integrations\IntegrationCaller;
use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Security\Ssrf\SsrfBlockedException;
use App\Services\Tools\Concerns\SubstitutesParameters;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class GraphqlExecutor implements ToolExecutor
{
    use SubstitutesParameters;

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private SafeHttpClient $safeHttp,
        private ToolConnectionResolver $connections,
        private IntegrationCaller $caller,
    ) {}

    public function execute(Tool $tool, array $parameters, array $config): ToolExecutionResult
    {
        // Connected tools borrow the endpoint + auth from the integration.
        $integration = $this->connections->resolve($tool);
        if ($integration instanceof Integration) {
            return $this->executeViaConnection($tool, $integration, $parameters, $config);
        }

        $startTime = microtime(true);

        try {
            $endpoint = $config['endpoint'] ?? '';
            $operation = $config['operation'] ?? '';
            $variables = $this->buildVariables($config, $parameters);

            $headers = array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $this->authHeaders($config));

            // Route through the SSRF guard, same as every user-controlled call.
            $response = $this->safeHttp->request('POST', $endpoint, [
                'headers' => $headers,
                'json' => [
                    'query' => $operation,
                    'variables' => $variables,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
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
        } catch (SsrfBlockedException $e) {
            Log::warning('GraphQL tool blocked by SSRF guard', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Blocked destination: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
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

    /**
     * Run through a Connection: the integration's base URL is the GraphQL
     * endpoint and supplies auth + SSRF guard + token refresh; the tool config
     * supplies only the operation and variables.
     */
    private function executeViaConnection(Tool $tool, Integration $integration, array $parameters, array $config): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $operation = $config['operation'] ?? '';
            $variables = $this->buildVariables($config, $parameters);

            $response = $this->caller->send($integration, 'POST', '', [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json' => [
                    'query' => $operation,
                    'variables' => $variables,
                ],
            ]);

            $executionTimeMs = (microtime(true) - $startTime) * 1000;
            $body = $response->json();

            if (isset($body['errors']) && ! empty($body['errors'])) {
                $errorMessages = collect($body['errors'])->pluck('message')->implode('; ');

                return ToolExecutionResult::failure(
                    error: 'GraphQL errors: '.$errorMessages,
                    statusCode: $response->status(),
                    metadata: [
                        'integration_id' => $integration->id,
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
                    metadata: ['integration_id' => $integration->id],
                    executionTimeMs: $executionTimeMs,
                );
            }

            $data = $this->mapResponse($body['data'] ?? $body, $config);

            return ToolExecutionResult::success(
                data: $data,
                metadata: [
                    'integration_id' => $integration->id,
                    'operation_type' => $config['operation_type'] ?? 'query',
                ],
                executionTimeMs: $executionTimeMs,
            );
        } catch (\Throwable $e) {
            Log::warning('GraphQL tool (connected) failed', [
                'tool_id' => $tool->id,
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
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

        // Connected tools borrow the endpoint + auth from the integration; only
        // the operation lives on the tool.
        if (! empty($config['integration_id'])) {
            if (empty($config['operation'])) {
                $errors['operation'] = 'GraphQL operation is required';
            }

            return $errors;
        }

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

    /**
     * @return array<string, string>
     */
    private function authHeaders(array $config): array
    {
        $authConfig = $config['auth_config'] ?? [];

        return match ($config['auth_type'] ?? 'none') {
            'bearer' => ($token = (string) ($authConfig['token'] ?? '')) !== ''
                ? ['Authorization' => 'Bearer '.$token]
                : [],
            'api_key' => [
                ($authConfig['header_name'] ?? 'X-API-Key') => (string) ($authConfig['key'] ?? ''),
            ],
            default => [],
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
