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

    /** Platform administration — the whole sysadmin tool suite. */
    public const PLATFORM_ADMIN = 'platform:admin';

    /** MCP tool-group abilities a token may be granted. */
    public const ABILITIES = ['apps:build', 'data:read', 'data:write', 'agents:invoke', self::PLATFORM_ADMIN];

    /**
     * Abilities an empty `abilities` list does NOT confer. Leaving the list
     * empty means "all tool groups" for the tenant-facing abilities — a
     * convenience that must never hand out platform administration by
     * omission. These are granted only by being named explicitly.
     *
     * @var list<string>
     */
    public const EXPLICIT_ONLY_ABILITIES = [self::PLATFORM_ADMIN];

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
        $granted = $this->abilities ?? [];

        if (in_array($ability, self::EXPLICIT_ONLY_ABILITIES, true)) {
            return in_array($ability, $granted, true);
        }

        if ($granted === []) {
            return true;
        }

        return in_array($ability, $granted, true);
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
