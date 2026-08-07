<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One place, at one moment.
 *
 * `gap` marks a point the app could not have followed the person to — the
 * phone was locked, the tab was buried. Kept because the SILENCE is the
 * information: a straight line drawn across a hole reads as a journey nobody
 * made, and the web has no background geolocation to fill it in.
 */
class LocationPing extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id', 'user_id', 'app_id', 'session_id',
        'lat', 'lng', 'accuracy_m', 'gap', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'integer',
            'gap' => 'boolean',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'lpg';
    }
}
