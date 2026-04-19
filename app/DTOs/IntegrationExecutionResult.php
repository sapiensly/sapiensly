<?php

namespace App\DTOs;

use App\Models\IntegrationExecution;

final class IntegrationExecutionResult
{
    /**
     * @param  array<string, string|array<int, string>>|null  $responseHeaders
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?int $status,
        public readonly ?array $responseHeaders,
        public readonly ?string $responseBody,
        public readonly ?int $responseSizeBytes,
        public readonly bool $responseTruncated,
        public readonly ?string $contentType,
        public readonly int $durationMs,
        public readonly ?string $error,
        public readonly ?IntegrationExecution $record,
    ) {}
}
