<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who signs in to a PORTAL — a customer, a supplier, an applicant.
 *
 * Not a {@see User}, and the distinction is load-bearing: a portal user belongs
 * to one app, holds no organization membership, no platform role and no
 * password, and can reach nothing outside that portal. Sharing the staff table
 * would make every existing "a user" query ambiguous, and the first one that
 * forgot to exclude them would be a breach rather than a bug.
 *
 * Their id is what a row_filter compares against, so this is the thing that
 * finally makes "each customer sees only their own records" expressible on a
 * public surface.
 */
class PortalUser extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    /** How long a magic link stays usable. */
    public const LINK_TTL_MINUTES = 30;

    protected $fillable = [
        'organization_id',
        'app_id',
        'email',
        'name',
        'status',
        'login_token_hash',
        'login_token_expires_at',
        'last_login_at',
    ];

    /** The token half is never serialised — it is a live credential. */
    protected $hidden = ['login_token_hash'];

    protected function casts(): array
    {
        return [
            'login_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'pusr';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    /**
     * What the runtime exposes as `current_user` for this person, so a
     * row_filter written as `{{current_user.id}}` finally resolves on a public
     * surface. Shaped exactly like the authenticated runtime's, so an app
     * authored for staff behaves the same for a portal visitor.
     *
     * @return array{id: string, email: string, name: string|null}
     */
    public function toExpressionContext(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
        ];
    }
}
