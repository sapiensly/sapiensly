<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credential for an app's REST data API.
 *
 * Two limits, and both apply:
 *  - `role_slug` is the CEILING. The key acts as that app role, so it can never
 *    reach past what a person holding the role could — the API is not a second
 *    permission system, it is the existing one addressed by key.
 *  - `scopes` is the GRANT. Least privilege per key: a key made for "read
 *    orders" says so, and stays useless for everything else even though its
 *    role could do more.
 *
 * Effective permission is the intersection. Widening a key therefore takes two
 * deliberate acts by two different mechanisms, which is the point.
 */
class AppApiKey extends Model
{
    use HasPrefixedUlid;
    use UsesPlatformConnection;

    /** Every action the API can perform, in the order they appear in a scope. */
    public const ACTIONS = ['read', 'create', 'update', 'delete'];

    protected $fillable = [
        'app_id',
        'organization_id',
        'created_by_user_id',
        'name',
        'prefix',
        'token_hash',
        'role_slug',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'akey';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Whether this key's own scope permits an action on an object. The role
     * still has to allow it too — this is the narrower of the two gates, never
     * the only one.
     */
    public function allows(string $objectSlug, string $action): bool
    {
        $objects = $this->scopes['objects'] ?? '*';

        // '*' means "whatever the role allows" — the key adds no narrowing.
        if ($objects === '*') {
            return true;
        }
        if (! is_array($objects)) {
            return false;
        }

        $granted = $objects[$objectSlug] ?? null;

        return is_array($granted) && in_array($action, $granted, true);
    }

    /**
     * The object slugs this key mentions, or null when it is unscoped. Used to
     * hide objects a key cannot touch from the discovery endpoint — a key
     * should not learn the shape of data it can never read.
     *
     * @return list<string>|null
     */
    public function scopedObjectSlugs(): ?array
    {
        $objects = $this->scopes['objects'] ?? '*';

        return is_array($objects) ? array_keys($objects) : null;
    }
}
