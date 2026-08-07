<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One device's key for saying who is holding it.
 *
 * Nothing here is secret — the private half never leaves the authenticator and
 * cannot be exported from it, so a stolen copy of this table lets nobody
 * approve anything. See the migration for why it is tenant data.
 */
class DeviceCredential extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'user_id',
        'app_id',
        'credential_id',
        'public_key',
        'sign_count',
        'label',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'sign_count' => 'integer'];
    }

    public static function getIdPrefix(): string
    {
        return 'dvc';
    }
}
