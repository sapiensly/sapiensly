<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use App\Models\IntegrationExecution;
use App\Models\IntegrationRequest;
use App\Models\User;
use App\Services\Integrations\Support\CredentialRedactor;

/**
 * Persists the outcome of a single IntegrationRequestExecutor run. All
 * sensitive artifacts (Authorization header, cookie, URL query secrets) are
 * redacted before the row is saved — the original in-memory values are left
 * untouched for the executor's return value.
 */
class IntegrationExecutionRecorder
{
    public function __construct(
        private CredentialRedactor $redactor,
    ) {}

    /**
     * @param  array<string, string|array<int, string>>  $requestHeaders
     * @param  array<string, string|array<int, string>>|null  $responseHeaders
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Integration $integration,
        ?IntegrationRequest $request,
        ?IntegrationEnvironment $environment,
        ?User $actor,
        string $method,
        string $url,
        array $requestHeaders,
        ?string $requestBody,
        ?int $responseStatus,
        ?array $responseHeaders,
        ?string $responseBody,
        ?int $responseSizeBytes,
        int $durationMs,
        bool $success,
        ?string $errorMessage,
        array $metadata = [],
    ): IntegrationExecution {
        $storeCap = (int) config('integrations.response_store_cap', 1_048_576);
        $truncatedBody = null;
        $truncated = false;

        if ($responseBody !== null) {
            if (strlen($responseBody) > $storeCap) {
                $truncatedBody = substr($responseBody, 0, $storeCap);
                $truncated = true;
            } else {
                $truncatedBody = $responseBody;
            }
        }

        $requestBodyStored = null;
        if ($requestBody !== null) {
            $requestBodyStored = substr($requestBody, 0, 65_536);
        }

        $metadata = array_merge(
            ['invoked_by' => 'user', 'truncated' => $truncated],
            $metadata,
        );

        return IntegrationExecution::create([
            'integration_id' => $integration->id,
            'integration_request_id' => $request?->id,
            'environment_id' => $environment?->id,
            'user_id' => $actor?->id,
            'organization_id' => $integration->organization_id,
            'method' => $method,
            'url' => $this->redactor->redactUrl($url),
            'request_headers' => $this->redactor->redactHeaders($requestHeaders),
            'request_body' => $requestBodyStored,
            'response_status' => $responseStatus,
            'response_headers' => $responseHeaders,
            'response_body' => $truncatedBody,
            'response_size_bytes' => $responseSizeBytes,
            'duration_ms' => $durationMs,
            'success' => $success,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }
}
