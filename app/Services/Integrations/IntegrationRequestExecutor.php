<?php

namespace App\Services\Integrations;

use App\DTOs\IntegrationExecutionResult;
use App\Enums\IntegrationAuthType;
use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use App\Models\IntegrationRequest;
use App\Models\User;
use App\Services\Integrations\Auth\AuthStrategyFactory;
use App\Services\Integrations\OAuth2\OAuth2ClientCredentialsFlow;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Integrations\Support\SsrfGuard;
use App\Services\Integrations\Support\VariableResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The runtime engine for an integration request. Resolves variables, builds
 * the outbound HTTP request with the correct auth, guards against SSRF, fires
 * the call, captures the response, and persists an IntegrationExecution row.
 */
class IntegrationRequestExecutor
{
    public function __construct(
        private AuthStrategyFactory $authFactory,
        private VariableResolver $variableResolver,
        private SsrfGuard $ssrfGuard,
        private IntegrationExecutionRecorder $recorder,
        private OAuth2TokenRefresher $oauth2Refresher,
        private OAuth2ClientCredentialsFlow $oauth2ClientCredentials,
    ) {}

    /**
     * @param  array<string, string>  $runtimeVariables
     */
    public function execute(
        IntegrationRequest $request,
        ?User $actor,
        ?IntegrationEnvironment $environment = null,
        array $runtimeVariables = [],
        string $invokedBy = 'user',
        bool $persist = true,
    ): IntegrationExecutionResult {
        $integration = $request->integration;
        $environment ??= $integration->activeEnvironment();

        return $this->perform(
            $integration,
            $request,
            $environment,
            $actor,
            $request->method,
            $request->path,
            $request->query_params ?? [],
            $request->headers ?? [],
            $request->body_type,
            $request->body_content,
            $request->timeout_ms ?: (int) config('integrations.max_timeout_ms', 30_000),
            $request->follow_redirects ?? true,
            $runtimeVariables,
            $invokedBy,
            $persist,
        );
    }

    /**
     * Fire an ad-hoc, unsaved request (e.g. a draft in the UI builder).
     *
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $queryParams
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $headers
     * @param  array<string, string>  $runtimeVariables
     */
    public function executeAdHoc(
        Integration $integration,
        ?User $actor,
        string $method,
        string $path,
        array $queryParams,
        array $headers,
        ?string $bodyType,
        ?string $bodyContent,
        ?IntegrationEnvironment $environment = null,
        array $runtimeVariables = [],
        int $timeoutMs = 30_000,
        bool $followRedirects = true,
        string $invokedBy = 'user',
    ): IntegrationExecutionResult {
        $environment ??= $integration->activeEnvironment();

        return $this->perform(
            $integration,
            null,
            $environment,
            $actor,
            $method,
            $path,
            $queryParams,
            $headers,
            $bodyType,
            $bodyContent,
            $timeoutMs,
            $followRedirects,
            $runtimeVariables,
            $invokedBy,
            true,
        );
    }

    /**
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $queryParams
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $headers
     * @param  array<string, string>  $runtimeVariables
     */
    private function perform(
        Integration $integration,
        ?IntegrationRequest $request,
        ?IntegrationEnvironment $environment,
        ?User $actor,
        string $method,
        string $path,
        array $queryParams,
        array $headers,
        ?string $bodyType,
        ?string $bodyContent,
        int $timeoutMs,
        bool $followRedirects,
        array $runtimeVariables,
        string $invokedBy,
        bool $persist,
    ): IntegrationExecutionResult {
        $env = $environment;
        if ($env !== null) {
            $env->loadMissing('variables');
        }

        $resolvedPath = $this->variableResolver->resolve($path, $runtimeVariables, $env);
        $resolvedBaseUrl = $this->variableResolver->resolve($integration->base_url, $runtimeVariables, $env);
        $url = rtrim($resolvedBaseUrl, '/').'/'.ltrim($resolvedPath, '/');

        // SSRF guard on the initial host. A blocked host is a legitimate
        // execution outcome (surfaced as success=false with a clear error),
        // not a server fault, so we record it and return a failed result
        // instead of bubbling the exception up to the HTTP stack.
        try {
            $this->ssrfGuard->assertHostAllowed($url, $this->allowInternal($integration));
        } catch (Throwable $e) {
            return $this->buildBlockedResult($integration, $request, $env, $actor, $method, $url, $invokedBy, $persist, $e->getMessage());
        }

        $authType = $integration->auth_type;

        // Refresh OAuth2 access tokens before applying the strategy. The
        // Authorization Code flow needs the user to complete consent first;
        // the executor never auto-redirects.
        if ($authType === IntegrationAuthType::OAuth2ClientCredentials) {
            $integration = $this->oauth2ClientCredentials->acquire($integration);
        } elseif ($authType === IntegrationAuthType::OAuth2AuthorizationCode) {
            $integration = $this->oauth2Refresher->refreshIfNeeded($integration);
        }

        $authConfig = $integration->auth_config ?? [];
        $strategy = $this->authFactory->make($authType);
        $applied = $strategy->apply($authConfig);

        $resolvedHeaders = array_merge(
            $this->flattenKvPairs($integration->default_headers ?? [], $runtimeVariables, $env),
            $this->flattenKvPairs($headers, $runtimeVariables, $env),
            $applied['headers'],
        );

        $resolvedQuery = array_merge(
            $this->flattenKvPairs($queryParams, $runtimeVariables, $env),
            $applied['query'],
        );

        $resolvedBody = $bodyContent;
        if ($resolvedBody !== null && $bodyType === 'json') {
            $resolvedBody = $this->variableResolver->resolveJson($bodyContent, $runtimeVariables, $env);
        } elseif ($resolvedBody !== null) {
            $resolvedBody = $this->variableResolver->resolve($bodyContent, $runtimeVariables, $env);
        }

        $timeoutMs = min($timeoutMs, (int) config('integrations.max_timeout_ms', 30_000));
        $maxRedirects = (int) config('integrations.max_redirects', 5);

        $pending = Http::withHeaders($resolvedHeaders)
            ->timeout((int) ceil($timeoutMs / 1000))
            ->withOptions([
                'allow_redirects' => $followRedirects
                    ? ['max' => $maxRedirects, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https'], 'track_redirects' => true]
                    : false,
                'verify' => ! $integration->allow_insecure_tls,
            ]);

        if (! empty($resolvedQuery)) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator.http_build_query($resolvedQuery);
        }

        $started = hrtime(true);
        $response = null;
        $error = null;
        $success = false;

        try {
            $response = $this->dispatch($pending, $method, $url, $resolvedBody, $bodyType);
            $success = $response->successful();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $responseStatus = $response?->status();
        $responseHeaders = $response?->headers();
        $responseBody = $response?->body();
        $responseSize = $response ? strlen($response->body()) : null;
        $contentType = $response?->header('Content-Type');

        $record = null;
        if ($persist) {
            $record = $this->recorder->record(
                integration: $integration,
                request: $request,
                environment: $env,
                actor: $actor,
                method: $method,
                url: $url,
                requestHeaders: $resolvedHeaders,
                requestBody: $resolvedBody,
                responseStatus: $responseStatus,
                responseHeaders: $responseHeaders,
                responseBody: $responseBody,
                responseSizeBytes: $responseSize,
                durationMs: $durationMs,
                success: $success,
                errorMessage: $error,
                metadata: ['invoked_by' => $invokedBy, 'content_type' => $contentType],
            );
        }

        $storeCap = (int) config('integrations.response_store_cap', 1_048_576);
        $truncated = $responseSize !== null && $responseSize > $storeCap;

        return new IntegrationExecutionResult(
            success: $success,
            status: $responseStatus,
            responseHeaders: $responseHeaders,
            responseBody: $responseBody !== null && $truncated
                ? substr($responseBody, 0, $storeCap)
                : $responseBody,
            responseSizeBytes: $responseSize,
            responseTruncated: $truncated,
            contentType: $contentType,
            durationMs: $durationMs,
            error: $error,
            record: $record,
        );
    }

    private function buildBlockedResult(
        Integration $integration,
        ?IntegrationRequest $request,
        ?IntegrationEnvironment $environment,
        ?User $actor,
        string $method,
        string $url,
        string $invokedBy,
        bool $persist,
        string $error,
    ): IntegrationExecutionResult {
        $record = null;
        if ($persist) {
            $record = $this->recorder->record(
                integration: $integration,
                request: $request,
                environment: $environment,
                actor: $actor,
                method: $method,
                url: $url,
                requestHeaders: [],
                requestBody: null,
                responseStatus: null,
                responseHeaders: null,
                responseBody: null,
                responseSizeBytes: null,
                durationMs: 0,
                success: false,
                errorMessage: $error,
                metadata: ['invoked_by' => $invokedBy, 'blocked' => true],
            );
        }

        return new IntegrationExecutionResult(
            success: false,
            status: null,
            responseHeaders: null,
            responseBody: null,
            responseSizeBytes: null,
            responseTruncated: false,
            contentType: null,
            durationMs: 0,
            error: $error,
            record: $record,
        );
    }

    private function dispatch(
        PendingRequest $pending,
        string $method,
        string $url,
        ?string $body,
        ?string $bodyType,
    ): Response {
        $method = strtoupper($method);

        if ($body === null || $body === '' || $bodyType === 'none' || $bodyType === null) {
            return $pending->send($method, $url);
        }

        if ($bodyType === 'json') {
            $decoded = json_decode($body, true);

            return $pending->send($method, $url, [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json'],
            ]);
        }

        if ($bodyType === 'form_urlencoded') {
            return $pending->asForm()->send($method, $url, [
                'form_params' => $this->parseFormBody($body),
            ]);
        }

        // Raw / other
        return $pending->send($method, $url, ['body' => $body]);
    }

    /**
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $pairs
     * @param  array<string, string>  $runtimeVariables
     * @return array<string, string>
     */
    private function flattenKvPairs(array $pairs, array $runtimeVariables, ?IntegrationEnvironment $env): array
    {
        $result = [];
        foreach ($pairs as $pair) {
            if (is_string($pair)) {
                continue;
            }
            if (($pair['enabled'] ?? true) === false) {
                continue;
            }
            $key = $pair['key'] ?? null;
            $value = $pair['value'] ?? null;
            if (! is_string($key) || $key === '' || $value === null) {
                continue;
            }
            $result[$key] = $this->variableResolver->resolve((string) $value, $runtimeVariables, $env);
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function parseFormBody(string $body): array
    {
        parse_str($body, $parsed);

        return array_map('strval', $parsed);
    }

    private function allowInternal(Integration $integration): bool
    {
        return (bool) config('integrations.allow_internal_hosts', false);
    }
}
