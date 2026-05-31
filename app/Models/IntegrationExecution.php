<?php

namespace App\Models;

use App\Casts\EncryptedRedactedJson;
use App\Casts\EncryptedRedactedText;
use App\Models\Concerns\HasPrefixedUlid;
use App\Services\Integrations\Support\CredentialRedactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationExecution extends Model
{
    use HasFactory, HasPrefixedUlid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'integration_id',
        'integration_request_id',
        'environment_id',
        'user_id',
        'organization_id',
        'method',
        'url',
        'request_headers',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'response_size_bytes',
        'duration_ms',
        'success',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            // The four blobs are redacted (known secrets removed for good) and
            // then encrypted at rest. url + error_message are redacted via the
            // mutators below (kept readable for the audit UI, so not encrypted).
            'request_headers' => EncryptedRedactedJson::class,
            'response_headers' => EncryptedRedactedJson::class,
            'request_body' => EncryptedRedactedText::class,
            'response_body' => EncryptedRedactedText::class,
            'metadata' => 'array',
            'success' => 'boolean',
            'response_size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'response_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected function setUrlAttribute(?string $value): void
    {
        $this->attributes['url'] = $value === null
            ? null
            : app(CredentialRedactor::class)->redactUrl($value);
    }

    protected function setErrorMessageAttribute(?string $value): void
    {
        $this->attributes['error_message'] = $value === null
            ? null
            : app(CredentialRedactor::class)->redactText($value);
    }

    public static function getIdPrefix(): string
    {
        return 'intexec';
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(IntegrationRequest::class, 'integration_request_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(IntegrationEnvironment::class, 'environment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
