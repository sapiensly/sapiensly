<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a file upload is requested but neither a tenant-level S3 bucket
 * nor a global S3 disk is configured. We refuse to silently fall back to local
 * storage — files would land on the app server's ephemeral disk and would
 * leak across deploys/instances. Always rendered as HTTP 503 with a clear
 * actionable message so operators know exactly what to fix.
 */
class TenantStorageNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'Object storage (S3) is not configured. Configure tenant-level S3 settings or set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and AWS_BUCKET in the environment to enable file uploads.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'error' => 'storage_not_configured',
            'message' => $this->getMessage(),
        ], 503);
    }
}
