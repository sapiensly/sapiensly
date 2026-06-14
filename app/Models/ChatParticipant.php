<?php

namespace App\Models;

use App\Ai\ChatAgent;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Roster row: an agent participating in a multi-agent (@mention) chat thread.
 *
 * Lives in the tenant schema (table `chat_agents`); the agent it points at lives
 * in `platform`. The `agent()` relation resolves across schemas the same way
 * {@see Chat::agent()} does. Named ChatParticipant — not ChatAgent — to avoid
 * colliding with the laravel/ai SDK wrapper {@see ChatAgent}.
 */
class ChatParticipant extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $table = 'chat_agents';

    protected $fillable = [
        'chat_id',
        'agent_id',
        'organization_id',
        'user_id',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cpar';
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
