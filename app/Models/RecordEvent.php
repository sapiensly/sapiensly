<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a record: a change it went through, or something
 * a person said about it.
 *
 * Both in one trail because they are read as one. Somebody opening an order
 * asks "why is this still waiting?" and the answer is half machine ("status:
 * recibida → esperando refacción, by Ana") and half human ("called the
 * customer, no answer").
 *
 * Tenant data — it quotes a record's values and names who changed them — so it
 * lives on the tenant connection under RLS, like the records it describes.
 */
class RecordEvent extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    public const KIND_CREATED = 'created';

    public const KIND_UPDATED = 'updated';

    public const KIND_DELETED = 'deleted';

    public const KIND_COMMENT = 'comment';

    protected $fillable = [
        'organization_id',
        'app_id',
        'record_id',
        'object_definition_id',
        'kind',
        'actor_user_id',
        'actor_name',
        'body',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'rev';
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
