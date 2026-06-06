<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationEnvironment extends Model
{
    use HasFactory, HasPrefixedUlid;
    use UsesPlatformConnection;

    protected $fillable = [
        'integration_id',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'intenv';
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(IntegrationVariable::class);
    }
}
