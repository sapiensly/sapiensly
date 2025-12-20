<?php

namespace App\DTOs;

use JsonSerializable;

class ToolExecutionResult implements JsonSerializable
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly ?string $error = null,
        public readonly ?int $statusCode = null,
        public readonly array $metadata = [],
        public readonly float $executionTimeMs = 0,
    ) {}

    public static function success(mixed $data, array $metadata = [], float $executionTimeMs = 0): self
    {
        return new self(
            success: true,
            data: $data,
            metadata: $metadata,
            executionTimeMs: $executionTimeMs,
        );
    }

    public static function failure(string $error, ?int $statusCode = null, array $metadata = [], float $executionTimeMs = 0): self
    {
        return new self(
            success: false,
            error: $error,
            statusCode: $statusCode,
            metadata: $metadata,
            executionTimeMs: $executionTimeMs,
        );
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'success' => $this->success,
            'data' => $this->data,
            'error' => $this->error,
            'status_code' => $this->statusCode,
            'metadata' => $this->metadata ?: null,
            'execution_time_ms' => $this->executionTimeMs,
        ], fn ($value) => $value !== null);
    }
}
