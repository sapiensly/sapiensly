<?php

namespace App\Services\Tools;

use App\Contracts\ToolExecutor;
use App\DTOs\ToolExecutionResult;
use App\Models\Tool;
use App\Services\Tools\Concerns\SubstitutesParameters;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RestApiExecutor implements ToolExecutor
{
    use SubstitutesParameters;

    private const TIMEOUT_SECONDS = 30;

    public function execute(Tool $tool, array $parameters, array $config): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $url = $this->buildUrl($config, $parameters);
            $method = strtoupper($config['method'] ?? 'GET');
            $headers = $this->buildHeaders($config, $parameters);
            $body = $this->buildBody($config, $parameters, $method);

            $request = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders($headers);

            // Add authentication
            $request = $this->applyAuthentication($request, $config);

            // Execute request
            $response = match ($method) {
                'GET' => $request->get($url, $body),
                'POST' => $request->post($url, $body),
                'PUT' => $request->put($url, $body),
                'PATCH' => $request->patch($url, $body),
                'DELETE' => $request->delete($url, $body),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            $executionTimeMs = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $data = $this->mapResponse($response->json() ?? $response->body(), $config);

                return ToolExecutionResult::success(
                    data: $data,
                    metadata: [
                        'status_code' => $response->status(),
                        'url' => $url,
                        'method' => $method,
                    ],
                    executionTimeMs: $executionTimeMs,
                );
            }

            return ToolExecutionResult::failure(
                error: "HTTP {$response->status()}: ".$this->extractErrorMessage($response),
                statusCode: $response->status(),
                metadata: [
                    'url' => $url,
                    'method' => $method,
                    'response_body' => $response->body(),
                ],
                executionTimeMs: $executionTimeMs,
            );
        } catch (ConnectionException $e) {
            Log::warning('REST API tool connection failed', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Connection failed: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } catch (RequestException $e) {
            Log::warning('REST API tool request failed', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Request failed: '.$e->getMessage(),
                statusCode: $e->response?->status(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } catch (\Exception $e) {
            Log::error('REST API tool execution error', [
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

        if (empty($config['base_url'])) {
            $errors['base_url'] = 'Base URL is required';
        }

        if (empty($config['method'])) {
            $errors['method'] = 'HTTP method is required';
        }

        if (! empty($config['auth_type']) && $config['auth_type'] !== 'none') {
            if (empty($config['auth_config'])) {
                $errors['auth_config'] = 'Authentication configuration is required';
            }
        }

        return $errors;
    }

    private function buildUrl(array $config, array $parameters): string
    {
        $baseUrl = rtrim($config['base_url'] ?? '', '/');
        $path = $config['path'] ?? '';

        if ($path) {
            $path = $this->substituteString($path, $parameters);
            $path = '/'.ltrim($path, '/');
        }

        return $baseUrl.$path;
    }

    private function buildHeaders(array $config, array $parameters): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (! empty($config['headers'])) {
            $headers = array_merge($headers, $this->substituteArray($config['headers'], $parameters));
        }

        return $headers;
    }

    private function buildBody(array $config, array $parameters, string $method): array
    {
        // GET and DELETE typically don't have request bodies
        if (in_array($method, ['GET', 'DELETE'])) {
            return $parameters;
        }

        if (! empty($config['request_body_template'])) {
            $template = $config['request_body_template'];

            // If template is a string (JSON), parse and substitute
            if (is_string($template)) {
                $substituted = $this->substituteString($template, $parameters);
                $decoded = json_decode($substituted, true);

                return $decoded !== null ? $decoded : $parameters;
            }

            // If template is already an array, substitute
            return $this->substituteArray($template, $parameters);
        }

        return $parameters;
    }

    private function applyAuthentication($request, array $config)
    {
        $authType = $config['auth_type'] ?? 'none';
        $authConfig = $config['auth_config'] ?? [];

        return match ($authType) {
            'bearer' => $request->withToken($authConfig['token'] ?? ''),
            'api_key' => $this->applyApiKeyAuth($request, $authConfig),
            'basic' => $request->withBasicAuth(
                $authConfig['username'] ?? '',
                $authConfig['password'] ?? ''
            ),
            'oauth2' => $request->withToken($authConfig['access_token'] ?? ''),
            default => $request,
        };
    }

    private function applyApiKeyAuth($request, array $authConfig)
    {
        $key = $authConfig['key'] ?? '';
        $value = $authConfig['value'] ?? '';
        $location = $authConfig['location'] ?? 'header';
        $name = $authConfig['name'] ?? 'X-API-Key';

        if ($location === 'header') {
            return $request->withHeaders([$name => $value]);
        }

        // Query parameter - handled differently
        return $request;
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

    private function extractErrorMessage($response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            return $body['message']
                ?? $body['error']
                ?? $body['error_description']
                ?? json_encode($body);
        }

        return $response->body() ?: 'Unknown error';
    }
}
