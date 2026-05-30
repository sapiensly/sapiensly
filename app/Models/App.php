<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class App extends Model
{
    /** @use HasFactory<AppFactory> */
    use HasFactory, HasPrefixedUlid, HasVisibility;

    protected $fillable = [
        'user_id',
        'organization_id',
        'slug',
        'name',
        'description',
        'icon',
        'color',
        'current_version_id',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'app';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class, 'app_id')->orderByDesc('version_number');
    }
}
