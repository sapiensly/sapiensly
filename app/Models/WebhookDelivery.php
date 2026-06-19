<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted inbound webhook delivery for a `webhook.inbound` workflow. The
 * unique (workflow_id, delivery_key) index makes this row the dedupe gate —
 * a provider's retry of the same delivery collides and is silently ignored.
 */
class WebhookDelivery extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'workflow_id',
        'delivery_key',
        'status',
    ];

    public static function getIdPrefix(): string
    {
        return 'whd';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }
}
