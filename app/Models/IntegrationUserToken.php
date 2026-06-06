<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single user's OAuth 2.0 tokens for an integration. The integration holds
 * the shared client configuration (org-level); the per-user authorization —
 * the access/refresh tokens minted by the Authorization Code flow — lives
 * here so members never share each other's tokens.
 */
class IntegrationUserToken extends Model
{
    use HasFactory;
    use UsesPlatformConnection;

    protected $fillable = [
        'user_id',
        'integration_id',
        'auth_config',
    ];

    protected function casts(): array
    {
        return [
            'auth_config' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isAuthorized(): bool
    {
        return ! empty(($this->auth_config ?? [])['access_token']);
    }
}
