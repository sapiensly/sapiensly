<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasPrefixedUlid;
    use SoftDeletes;
    use UsesPlatformConnection;

    protected $fillable = [
        'activity_retention_months',
        'name',
        'slug',
        'metadata',
        'brand',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'brand' => 'array',
        ];
    }

    /**
     * The organization's Brandbook as a normalized value object (empty when
     * unset). The single source of truth every customizable surface inherits.
     */
    public function brandbook(): OrganizationBrand
    {
        return OrganizationBrand::fromArray($this->brand);
    }

    /**
     * Slugs an organization may not take, because a route already owns them.
     *
     * `platform` is the organization-free MCP endpoint (`mcp/platform/v1`),
     * registered ahead of `mcp/{organization}/v1`. An org that took the slug
     * would not break the platform endpoint — it would simply become
     * unreachable over MCP itself, silently, which is worse than being told no
     * at creation time.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = ['platform'];

    public static function slugIsReserved(?string $slug): bool
    {
        return in_array(strtolower(trim((string) $slug)), self::RESERVED_SLUGS, true);
    }

    public static function getIdPrefix(): string
    {
        return 'org';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function ssoConnection(): HasOne
    {
        return $this->hasOne(OrganizationSsoConnection::class);
    }

    public function aiBudget(): HasOne
    {
        return $this->hasOne(OrganizationAiBudget::class);
    }

    /**
     * The organization Contextbook — the business knowledge every model
     * interaction in this organization is grounded in. The Brandbook's
     * counterpart: one says how the organization looks, this one says what it is.
     */
    public function aiContext(): HasOne
    {
        return $this->hasOne(OrganizationAiContext::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
