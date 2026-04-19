<?php

namespace App\Services\Integrations\Support;

/**
 * Strips sensitive material from request artifacts before they are written
 * to an IntegrationExecution record. Preserves the original request so the
 * outbound call still works; only the *stored* copy is sanitised.
 */
class CredentialRedactor
{
    private const REDACTED = '[REDACTED]';

    /**
     * @var array<int, string>
     */
    private const BASE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'cookie',
        'set-cookie',
        'x-auth-token',
    ];

    /**
     * @var array<int, string>
     */
    private const BASE_URL_KEYS = [
        'api_key',
        'apikey',
        'token',
        'access_token',
        'key',
        'password',
        'secret',
    ];

    /**
     * @param  array<string, string|array<int, string>>  $headers
     * @return array<string, string|array<int, string>>
     */
    public function redactHeaders(array $headers): array
    {
        $redacted = [];
        $extra = array_map('strtolower', (array) config('integrations.redact_headers', []));
        $denyList = array_unique(array_merge(self::BASE_HEADERS, $extra));

        foreach ($headers as $name => $value) {
            $redacted[$name] = in_array(strtolower((string) $name), $denyList, true)
                ? self::REDACTED
                : $value;
        }

        return $redacted;
    }

    public function redactUrl(string $url): string
    {
        // Strip credentials embedded as userinfo.
        $clean = preg_replace('#://[^/@]+@#', '://', $url);
        if ($clean === null) {
            $clean = $url;
        }

        $pattern = '/([?&](?:'.implode('|', array_map('preg_quote', self::BASE_URL_KEYS)).')=)[^&#]*/i';
        $result = preg_replace($pattern, '$1'.self::REDACTED, $clean);

        return $result ?? $clean;
    }
}
