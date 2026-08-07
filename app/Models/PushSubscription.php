<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One browser that has agreed to be told something by one app.
 *
 * A row is a device, not a person — the same technician has a phone and a
 * desktop, and each hands out its own endpoint and its own key pair. Tenant
 * data under RLS: see the migration for why.
 */
class PushSubscription extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'user_id',
        'app_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
    ];

    protected $hidden = [
        // Not a secret in the usual sense — it IS the address — but nothing
        // that serialises a subscription has any business carrying the keys,
        // and a payload encrypted to them is the only thing that ever should.
        'p256dh',
        'auth',
    ];

    public static function getIdPrefix(): string
    {
        return 'psb';
    }

    /**
     * What makes two subscriptions the same one.
     *
     * A browser re-registers on every visit and may hand back the SAME endpoint
     * with a fresh key pair after a service worker update — so the endpoint is
     * the identity and the keys are the part that gets replaced.
     */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
