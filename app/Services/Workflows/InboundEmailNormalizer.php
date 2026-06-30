<?php

namespace App\Services\Workflows;

/**
 * Maps an inbound-email provider's webhook payload into one canonical shape
 * {from, from_name, to, subject, text, html, message_id} so an `email.inbound`
 * workflow reads the same fields regardless of provider.
 *
 * Open to any provider: known providers (postmark/mailgun/sendgrid) have exact
 * field maps; everything else falls back to `generic`, which tries the common
 * field names. The provider is chosen by the integration's
 * `auth_config['email_provider']` (default `generic`).
 */
class InboundEmailNormalizer
{
    private const FIELDS = ['from', 'from_name', 'to', 'subject', 'text', 'html', 'message_id'];

    /** Exact field maps for well-known providers (canonical => payload path). */
    private const PRESETS = [
        'postmark' => [
            'from' => 'From', 'from_name' => 'FromName', 'to' => 'To',
            'subject' => 'Subject', 'text' => 'TextBody', 'html' => 'HtmlBody',
            'message_id' => 'MessageID',
        ],
        'mailgun' => [
            'from' => 'sender', 'from_name' => 'from', 'to' => 'recipient',
            'subject' => 'subject', 'text' => 'body-plain', 'html' => 'body-html',
            'message_id' => 'Message-Id',
        ],
        'sendgrid' => [
            'from' => 'from', 'from_name' => 'from', 'to' => 'to',
            'subject' => 'subject', 'text' => 'text', 'html' => 'html',
            'message_id' => 'message_id',
        ],
    ];

    /** Candidate keys tried in order for an unknown provider. */
    private const GENERIC = [
        'from' => ['from', 'From', 'sender', 'from_email'],
        'from_name' => ['from_name', 'FromName', 'sender_name'],
        'to' => ['to', 'To', 'recipient', 'to_email'],
        'subject' => ['subject', 'Subject'],
        'text' => ['text', 'TextBody', 'body-plain', 'plain', 'body'],
        'html' => ['html', 'HtmlBody', 'body-html'],
        'message_id' => ['message_id', 'MessageID', 'Message-Id', 'message-id', 'id'],
    ];

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string|null>
     */
    public function normalize(string $provider, array $body): array
    {
        $preset = self::PRESETS[strtolower($provider)] ?? null;

        $email = [];
        foreach (self::FIELDS as $field) {
            $email[$field] = $preset !== null
                ? $this->stringOrNull(data_get($body, $preset[$field]))
                : $this->firstPresent($body, self::GENERIC[$field]);
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $candidates
     */
    private function firstPresent(array $body, array $candidates): ?string
    {
        foreach ($candidates as $key) {
            $value = data_get($body, $key);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
