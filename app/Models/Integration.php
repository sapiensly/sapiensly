<?php

namespace App\Models;

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'name',
        'slug',
        'description',
        'base_url',
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
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'integ';
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
