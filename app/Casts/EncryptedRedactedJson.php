<?php

namespace App\Casts;

use App\Services\Integrations\Support\CredentialRedactor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Cast for header maps stored in an audit row: redact sensitive header values,
 * then encrypt at rest. Two layers — the redaction makes known secrets
 * irreversibly gone (survives the round-trip as [REDACTED]); the encryption
 * protects whatever the redactor didn't recognise against an at-rest dump.
 *
 * STRICT on read: a value that won't decrypt (key rotation without
 * APP_PREVIOUS_KEYS, or a plaintext row injected outside the model) throws
 * rather than masking corruption as valid data.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
class EncryptedRedactedJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return json_decode(Crypt::decryptString($value), true);
    }

    /**
     * @return array<string, ?string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $headers = is_array($value) ? $value : (json_decode((string) $value, true) ?? []);
        $redacted = app(CredentialRedactor::class)->redactHeaders($headers);

        return [$key => Crypt::encryptString(json_encode($redacted))];
    }
}
