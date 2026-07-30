<?php

namespace App\Services\Notifications;

/**
 * One resolved destination: an address, and the account behind it when there is
 * one. The user matters even for an email send — it is what lets the same step
 * also drop an in-app notification, and what the delivery log records.
 */
final class NotificationRecipient
{
    public function __construct(
        public readonly ?string $email,
        public readonly ?int $userId = null,
        public readonly ?string $name = null,
    ) {}

    /** The key two recipients are considered the same by. */
    public function fingerprint(): string
    {
        return $this->userId !== null ? 'u:'.$this->userId : 'e:'.strtolower((string) $this->email);
    }
}
