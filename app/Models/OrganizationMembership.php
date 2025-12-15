<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMembership extends Model
{
    use HasPrefixedUlid;

    protected $fillable = [
        'organization_id',
        'user_id',
        'workos_membership_id',
        'role',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'mem';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isAdmin(): bool
    {
        return $this->role === MembershipRole::Admin;
    }
}
