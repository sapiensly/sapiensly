<?php

namespace App\Services\WhatsApp;

/**
 * Thrown when an outbound send is blocked by a compliance/config gate before
 * the message even reaches the provider (opt-out, 24h window, paused channel).
 * The code is a short kebab-case identifier the UI can translate.
 */
class WhatsAppSendException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
