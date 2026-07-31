<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Models\Concerns\UsesPlatformConnection;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    use UsesPlatformConnection;

    /** The platform-wide super-admin role, assigned with a null spatie team. */
    public const SYSADMIN_ROLE = 'sysadmin';

    /** Per-instance memo for {@see self::isSysAdmin()}. */
    private ?bool $sysAdminMemo = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'password',
        'organization_id',
        'avatar',
        'locale',
        'blocked_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function aiProviders(): HasMany
    {
        return $this->hasMany(AiProvider::class);
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** App-role grants this user holds (the runtime role per app). */
    public function appRoles(): HasMany
    {
        return $this->hasMany(AppUserRole::class, 'assigned_user_id');
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Whether the user holds the PLATFORM-wide sysadmin role.
     *
     * `hasRole('sysadmin')` alone is not enough: spatie's teams feature scopes
     * the roles relation to the current team (`organization_id`), while the
     * sysadmin role is assigned globally with a null team (see SysAdminSeeder).
     * So the moment a team is set — every MCP request pins one, and so does
     * SetPermissionsTeam on the web — the check silently returns false.
     *
     * This reads the assignment straight from the pivot, deliberately ignoring
     * the team scope rather than temporarily changing it. Swapping the global
     * team id would work, but this runs inside `Gate::before` on every
     * authorization check: it must not mutate shared state, and it must not
     * disturb an already-eager-loaded `roles` relation (unsetting it there would
     * turn one eager load into an N+1 across the request). Memoized per
     * instance, so it costs at most one small query per user object.
     */
    public function isSysAdmin(): bool
    {
        if ($this->sysAdminMemo !== null) {
            return $this->sysAdminMemo;
        }

        $pivot = config('permission.table_names.model_has_roles', 'model_has_roles');
        $roles = config('permission.table_names.roles', 'roles');
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');

        return $this->sysAdminMemo = $this->newQuery()->getConnection()
            ->table($pivot)
            ->join($roles, $roles.'.id', '=', $pivot.'.role_id')
            ->where($pivot.'.'.$morphKey, $this->getKey())
            ->where($pivot.'.model_type', $this->getMorphClass())
            ->where($roles.'.name', self::SYSADMIN_ROLE)
            ->exists();
    }

    /** Forget the memoized sysadmin answer (after granting/revoking the role). */
    public function forgetSysAdminMemo(): void
    {
        $this->sysAdminMemo = null;
    }

    /**
     * Locale used when Laravel renders notifications for this user — the
     * framework picks this up automatically for queued and sync mail, so
     * auth emails (verify / reset / 2FA) arrive in the recipient's chosen
     * language without each notification having to call App::setLocale.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? config('app.fallback_locale');
    }

    public function hasOrganization(): bool
    {
        return $this->organization_id !== null;
    }

    public function belongsToOrganization(string $organizationId): bool
    {
        return $this->organization_id === $organizationId;
    }

    /**
     * Whether the user currently belongs to the organization — either it is
     * their active org or they hold an active membership in it. Used to bind an
     * MCP credential to an org regardless of the user's active-org pointer.
     */
    public function isActiveMemberOf(string $organizationId): bool
    {
        if ($this->organization_id === $organizationId) {
            return true;
        }

        return $this->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }
}
