<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token an external MCP client uses to authenticate as a Sapiensly
 * user. The token resolves to its owning user; that user's organization_id then
 * drives tenant scope (RLS), mirroring ChatbotApiToken → chatbot owner for the
 * widget API. `abilities` gates which MCP tool groups the token may use.
 */
class McpAccessToken extends Model
{
    use HasFactory, HasPrefixedUlid;
    use UsesPlatformConnection;

    /** MCP tool-group abilities a token may be granted. */
    public const ABILITIES = ['apps:build', 'data:read', 'data:write', 'agents:invoke'];

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'token',
        'abilities',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'token',
    ];

    public static function getIdPrefix(): string
    {
        return 'mcp';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function generateToken(): string
    {
        return hash('sha256', Str::random(40));
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function hasAbility(string $ability): bool
    {
        if (empty($this->abilities)) {
            return true;
        }

        return in_array($ability, $this->abilities, true);
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
