<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a runtime agent conversation (builder power #3). `action_payload`
 * carries a proposed action awaiting human approval (the write slice's gate);
 * for plain read turns it is null. Tenant data — RLS-scoped.
 */
class RuntimeAgentMessage extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'message_type',
        'action_payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'action_payload' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'rmsg';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(RuntimeAgentConversation::class, 'conversation_id');
    }
}
