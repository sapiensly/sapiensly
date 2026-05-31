<?php

namespace App\Services\Integrations\Support;

/**
 * Strips sensitive material from request/response artifacts before they are
 * stored. Preserves the original in-memory request so the outbound call still
 * works; only the *stored* copy is sanitised.
 *
 * This is the single shared redactor — headers, structured bodies, URLs and
 * best-effort free text. Its sensitive lists live in config/security.php so
 * they never drift. Reuse it anywhere an audit artifact is persisted (e.g. a
 * future WorkflowStepRun http.request log) rather than reinventing it.
 *
 * All operations are idempotent: redacting already-redacted input is a no-op.
 */
class CredentialRedactor
{
    private const REDACTED = '[REDACTED]';

    private const UNPARSEABLE = '[UNPARSEABLE_REDACTED]';

    /** Base header deny-list (merged with config). */
    private const BASE_HEADERS = [
        'authorization', 'proxy-authorization', 'x-api-key', 'api-key',
        'cookie', 'set-cookie', 'x-auth-token',
    ];

    /** Base URL/body key deny-list (merged with config). */
    private const BASE_KEYS = [
        'api_key', 'apikey', 'token', 'access_token', 'key', 'password', 'secret',
    ];

    /**
     * @param  array<string, string|array<int, string>>  $headers
     * @return array<string, string|array<int, string>>
     */
    public function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $redacted[$name] = $this->isSensitiveHeader((string) $name) ? self::REDACTED : $value;
        }

        return $redacted;
    }

    /**
     * Redact a structured (JSON / form-urlencoded) body by field name. The body
     * is also encrypted at rest by the cast, so unknown content types fall back
     * to that protection rather than being mangled. When $contentType is null
     * (e.g. called from a cast) the shape is auto-detected.
     */
    public function redactStructuredBody(?string $body, ?string $contentType = null): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $type = strtolower((string) $contentType);
        $looksJson = str_contains($type, 'json')
            || ($contentType === null && in_array(substr(ltrim($body), 0, 1), ['{', '['], true));

        if ($looksJson) {
            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                // Declared/looked like JSON but won't parse. The cast still
                // encrypts the return, so returning the raw body never leaks in
                // plaintext; mark it so a reader knows it wasn't redactable.
                return $contentType !== null ? self::UNPARSEABLE : $body;
            }

            return json_encode($this->redactArray($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $looksForm = str_contains($type, 'x-www-form-urlencoded')
            || ($contentType === null && preg_match('/^[^=&\s]+=[^&]*(&[^=&\s]+=[^&]*)*$/', $body) === 1);

        if ($looksForm) {
            parse_str($body, $parsed);

            return http_build_query($this->redactArray($parsed));
        }

        // Unknown content type: leave to the encryption layer.
        return $body;
    }

    public function redactUrl(string $url): string
    {
        // Strip credentials embedded as userinfo.
        $clean = preg_replace('#://[^/@]+@#', '://', $url) ?? $url;

        $keys = array_map('preg_quote', $this->urlKeys());
        $pattern = '/([?&](?:'.implode('|', $keys).')=)[^&#]*/i';

        return preg_replace($pattern, '$1'.self::REDACTED, $clean) ?? $clean;
    }

    /**
     * Best-effort redaction over free text (e.g. exception messages that may
     * embed a token). Not structured — catches the common shapes only.
     */
    public function redactText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Bearer / token-scheme values.
        $text = preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._\-+\/=]+/i', '$1 '.self::REDACTED, $text) ?? $text;

        // key=value / "key": "value" for sensitive field names.
        $names = implode('|', array_map('preg_quote', $this->fieldKeys()));
        $text = preg_replace(
            '/(["\']?(?:'.$names.')["\']?\s*[:=]\s*["\']?)[^\s"\',&}]+/i',
            '${1}'.self::REDACTED,
            $text,
        ) ?? $text;

        return $text;
    }

    private function isSensitiveHeader(string $name): bool
    {
        $lower = strtolower($name);

        $exact = array_unique(array_merge(
            self::BASE_HEADERS,
            array_map('strtolower', (array) config('security.redaction.sensitive_headers', [])),
            array_map('strtolower', (array) config('integrations.redact_headers', [])), // legacy key, still honoured
        ));
        if (in_array($lower, $exact, true)) {
            return true;
        }

        foreach ((array) config('security.redaction.sensitive_header_suffixes', []) as $suffix) {
            if (str_ends_with($lower, strtolower((string) $suffix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively redact array values whose key is sensitive, preserving keys
     * and structure (including inside nested arrays/objects).
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function redactArray(array $data): array
    {
        $fields = $this->fieldKeys();

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactArray($value);

                continue;
            }
            if (is_string($key) && in_array(strtolower($key), $fields, true)) {
                $data[$key] = self::REDACTED;
            }
        }

        return $data;
    }

    /** @return list<string> */
    private function fieldKeys(): array
    {
        return array_values(array_unique(array_map(
            'strtolower',
            (array) config('security.redaction.sensitive_fields', self::BASE_KEYS),
        )));
    }

    /** @return list<string> */
    private function urlKeys(): array
    {
        return array_values(array_unique(array_merge(self::BASE_KEYS, $this->fieldKeys())));
    }
}
