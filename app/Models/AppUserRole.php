<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\AppUserRoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants an organization member a role (by manifest role `slug`) on a specific
 * app. The runtime resolves a user's effective app role from these rows via
 * AppAccessResolver. Mutable per-user grant data → tenant schema, RLS-protected
 * (see the 2026_06_27_90000x migrations). The role slug is stored (not the
 * regenerated manifest role id) so a grant survives manifest edits.
 */
class AppUserRole extends Model
{
    /** @use HasFactory<AppUserRoleFactory> */
    use HasFactory, HasPrefixedUlid, UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'user_id',
        'app_id',
        'assigned_user_id',
        'role_slug',
        'granted_by_user_id',
    ];

    public static function getIdPrefix(): string
    {
        return 'aur';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /** The organization member who holds this app role. */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
