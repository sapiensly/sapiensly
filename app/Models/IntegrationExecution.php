<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
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
            'request_headers' => 'array',
            'response_headers' => 'array',
            'metadata' => 'array',
            'success' => 'boolean',
            'response_size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'response_status' => 'integer',
            'created_at' => 'datetime',
        ];
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
