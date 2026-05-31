<?php

namespace App\Casts;

use App\Services\Integrations\Support\CredentialRedactor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Cast for request/response bodies stored in an audit row: redact sensitive
 * fields from structured (JSON/form) content, then encrypt at rest. Content
 * type is auto-detected (the cast has no header context); unknown shapes are
 * left to the encryption layer rather than mangled.
 *
 * STRICT on read — see EncryptedRedactedJson.
 *
 * @implements CastsAttributes<string, string>
 */
class EncryptedRedactedText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::decryptString($value);
    }

    /**
     * @return array<string, ?string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $redacted = app(CredentialRedactor::class)->redactStructuredBody((string) $value);

        return [$key => $redacted === null ? null : Crypt::encryptString($redacted)];
    }
}
