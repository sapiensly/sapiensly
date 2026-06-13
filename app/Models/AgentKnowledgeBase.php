<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row linking an {@see Agent} (platform schema) to a {@see KnowledgeBase}
 * (tenant schema).
 *
 * Both the agent and this pivot live in `platform`, while the knowledge base now
 * lives in `tenant`. A `belongsToMany` from Agent to KnowledgeBase would run on
 * the tenant connection and try to join `agent_knowledge_bases`, which the
 * tenant role cannot see — so we read/mutate the link rows directly on the
 * platform connection and resolve the knowledge bases separately under tenant
 * scope (see {@see Agent::loadKnowledgeBases()}).
 */
class AgentKnowledgeBase extends Model
{
    use UsesPlatformConnection;

    protected $table = 'agent_knowledge_bases';

    protected $fillable = [
        'agent_id',
        'knowledge_base_id',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
