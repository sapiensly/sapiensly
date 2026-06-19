<?php

namespace App\Services\Tools;

use App\Contracts\ToolExecutor;
use App\DTOs\ToolExecutionResult;
use App\Models\Tool;
use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Security\Ssrf\SsrfBlockedException;
use App\Services\Tools\Concerns\SubstitutesParameters;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class RestApiExecutor implements ToolExecutor
{
    use SubstitutesParameters;

    private const TIMEOUT_SECONDS = 30;

    public function __construct(private SafeHttpClient $safeHttp) {}

    public function execute(Tool $tool, array $parameters, array $config): ToolExecutionResult
    {
        $startTime = microtime(true);

        try {
            $url = $this->buildUrl($config, $parameters);
            $method = strtoupper($config['method'] ?? 'GET');
            $headers = $this->buildHeaders($config, $parameters);
            $payload = $this->buildBody($config, $parameters, $method);

            [$authHeaders, $authQuery] = $this->authHeadersAndQuery($config);
            $headers = array_merge($headers, $authHeaders);

            // Route through the SSRF guard: a connector call carries a
            // user-controlled URL, so it gets the same DNS validation, IP
            // pinning (anti-rebinding) and redirect re-validation as http.request.
            $options = ['headers' => $headers, 'timeout' => self::TIMEOUT_SECONDS];
            if (in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
                $query = array_merge($authQuery, $payload);
                if ($query !== []) {
                    $options['query'] = $query;
                }
            } else {
                if ($authQuery !== []) {
                    $options['query'] = $authQuery;
                }
                $options['json'] = $payload;
            }

            $response = $this->safeHttp->request($method, $url, $options);

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
        } catch (SsrfBlockedException $e) {
            Log::warning('REST API tool blocked by SSRF guard', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Blocked destination: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
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

    /**
     * Translate the tool's auth config into header and query maps the
     * SafeHttpClient can carry (it sends, so auth can't ride on a PendingRequest).
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function authHeadersAndQuery(array $config): array
    {
        $authType = $config['auth_type'] ?? 'none';
        $authConfig = $config['auth_config'] ?? [];
        $headers = [];
        $query = [];

        switch ($authType) {
            case 'bearer':
                $token = (string) ($authConfig['token'] ?? '');
                if ($token !== '') {
                    $headers['Authorization'] = 'Bearer '.$token;
                }
                break;
            case 'api_key':
                $name = $authConfig['name'] ?? 'X-API-Key';
                $value = (string) ($authConfig['value'] ?? '');
                if (($authConfig['location'] ?? 'header') === 'query') {
                    $query[$name] = $value;
                } else {
                    $headers[$name] = $value;
                }
                break;
            case 'basic':
                $headers['Authorization'] = 'Basic '.base64_encode(
                    ($authConfig['username'] ?? '').':'.($authConfig['password'] ?? '')
                );
                break;
            case 'oauth2':
                $accessToken = (string) ($authConfig['access_token'] ?? '');
                if ($accessToken !== '') {
                    $headers['Authorization'] = 'Bearer '.$accessToken;
                }
                break;
        }

        return [$headers, $query];
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
