<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's own hostname pointed at a published landing — the routing key the
 * public surface resolves by Host header. Control-plane row (platform schema);
 * only `active` domains are ever served.
 */
class CustomDomain extends Model
{
    use HasPrefixedUlid;
    use UsesPlatformConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'user_id',
        'app_id',
        'hostname',
        'status',
        'cf_hostname_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'dom';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * @param  Builder<CustomDomain>  $query
     * @return Builder<CustomDomain>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
