<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudProvider extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'kind',
        'driver',
        'display_name',
        'credentials',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'visibility' => Visibility::class,
            'is_default' => 'boolean',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cloud';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isStorage(): bool
    {
        return $this->kind === 'storage';
    }

    public function isDatabase(): bool
    {
        return $this->kind === 'database';
    }
}
