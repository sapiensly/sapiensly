<?php

namespace App\Services\WhatsApp;

/**
 * Thrown by WhatsAppProviderContract implementations when the upstream WABA
 * API rejects a request. Carries the provider error code (e.g. Meta's 131056
 * for "rate limit hit") and the HTTP status for decision-making upstream.
 */
class WhatsAppProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $providerErrorCode = 0,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message, $providerErrorCode);
    }

    public function isRateLimited(): bool
    {
        return $this->providerErrorCode === 131056 || $this->httpStatus === 429;
    }

    public function isAuthError(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }
}
