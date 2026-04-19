<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationVariable extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $fillable = [
        'integration_environment_id',
        'key',
        'value',
        'is_secret',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'intvar';
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(IntegrationEnvironment::class, 'integration_environment_id');
    }
}
