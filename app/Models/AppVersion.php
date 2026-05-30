<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Database\Factories\AppVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppVersion extends Model
{
    /** @use HasFactory<AppVersionFactory> */
    use HasFactory, HasPrefixedUlid;

    const UPDATED_AT = null;

    protected $fillable = [
        'app_id',
        'organization_id',
        'version_number',
        'manifest',
        'created_by_user_id',
        'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'version_number' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'apv';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
