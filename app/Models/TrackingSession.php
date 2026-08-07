<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stretch of time somebody agreed to be followed for.
 *
 * Started by a person, not by an app opening — and it carries the geofence
 * state, so an arrival fires once rather than once per fix at the edge of the
 * fence. See the migration for why this is not records in the app's objects.
 */
class TrackingSession extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id', 'user_id', 'app_id',
        'object_id', 'record_id',
        'target_lat', 'target_lng', 'radius_m',
        'inside', 'started_at', 'last_ping_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'ended_at' => 'datetime',
            'inside' => 'boolean',
            'target_lat' => 'float',
            'target_lng' => 'float',
            'radius_m' => 'integer',
        ];
    }

    public function pings(): HasMany
    {
        return $this->hasMany(LocationPing::class, 'session_id');
    }

    /** Whether this session is still meant to be reporting. */
    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    public static function getIdPrefix(): string
    {
        return 'trk';
    }
}
