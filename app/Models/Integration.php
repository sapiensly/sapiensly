<?php

namespace App\Models;

use App\Enums\IntegrationAuthType;
use App\Enums\IntegrationKind;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;
    use UsesPlatformConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'name',
        'slug',
        'description',
        'base_url',
        'is_mcp',
        'kind',
        'auth_type',
        'auth_config',
        'default_headers',
        'active_environment_id',
        'status',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'color',
        'icon',
        'allow_insecure_tls',
    ];

    protected function casts(): array
    {
        return [
            'auth_config' => 'encrypted:array',
            'default_headers' => 'array',
            'visibility' => Visibility::class,
            'auth_type' => IntegrationAuthType::class,
            'last_tested_at' => 'datetime',
            'allow_insecure_tls' => 'boolean',
            'is_mcp' => 'boolean',
            'kind' => IntegrationKind::class,
        ];
    }

    protected static function booted(): void
    {
        // Bridge the legacy is_mcp flag and the kind discriminator so both stay
        // consistent however a row is created (legacy callers set is_mcp; the
        // new flows set kind).
        static::saving(function (Integration $integration): void {
            if ($integration->kind === null) {
                $integration->kind = $integration->is_mcp
                    ? IntegrationKind::Mcp
                    : IntegrationKind::Http;
            }

            $integration->is_mcp = $integration->kind === IntegrationKind::Mcp;
        });
    }

    public static function getIdPrefix(): string
    {
        return 'integ';
    }

    public function isDatabase(): bool
    {
        return $this->kind === IntegrationKind::Database;
    }

    /**
     * The DSN-shaped connection config for a database connection — the same
     * shape DatabaseConnectionFactory and the legacy database tool config use.
     * Lives (encrypted) in auth_config.
     *
     * @return array<string, mixed>
     */
    public function databaseConnectionConfig(): array
    {
        return $this->auth_config ?? [];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(IntegrationEnvironment::class)->orderBy('sort_order')->orderBy('name');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(IntegrationRequest::class)->orderBy('sort_order');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(IntegrationExecution::class)->latest();
    }

    public function activeEnvironment(): ?IntegrationEnvironment
    {
        if (! $this->active_environment_id) {
            return null;
        }

        return $this->environments->firstWhere('id', $this->active_environment_id);
    }
}
