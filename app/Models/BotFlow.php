<?php

namespace App\Models;

use App\Enums\BotFlowStatus;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotFlow extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility;
    use UsesPlatformConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'agent_id',
        'chatbot_id',
        'name',
        'description',
        'status',
        'visibility',
        'definition',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'status' => BotFlowStatus::class,
            'visibility' => Visibility::class,
            'definition' => 'array',
            'version' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'flow';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    /**
     * The agent roster declared by this flow's `agent` nodes, keyed by role.
     * The orchestrator resolves handoffs (target_agent) against this map.
     *
     * @return array{triage: ?Agent, knowledge: ?Agent, action: ?Agent}
     */
    public function roster(): array
    {
        $byRole = [];
        foreach ($this->definition['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) !== 'agent') {
                continue;
            }
            $role = $node['data']['role'] ?? null;
            $agentId = $node['data']['agent_id'] ?? null;
            if ($role !== null && $agentId !== null && ! isset($byRole[$role])) {
                $byRole[$role] = $agentId;
            }
        }

        $agents = Agent::query()->whereIn('id', array_values($byRole))->get()->keyBy('id');

        return [
            'triage' => isset($byRole['triage']) ? $agents->get($byRole['triage']) : null,
            'knowledge' => isset($byRole['knowledge']) ? $agents->get($byRole['knowledge']) : null,
            'action' => isset($byRole['action']) ? $agents->get($byRole['action']) : null,
        ];
    }

    /**
     * Distinct agents declared by this flow, nulls removed.
     * A single-agent roster runs as direct LLM chat (no orchestration).
     *
     * @return list<Agent>
     */
    public function rosterAgents(): array
    {
        return array_values(array_filter($this->roster()));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BotFlowStatus::Active);
    }

    /**
     * Get the start node from the flow definition.
     *
     * @return array<string, mixed>|null
     */
    public function getStartNode(): ?array
    {
        $nodes = $this->definition['nodes'] ?? [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'start') {
                return $node;
            }
        }

        return null;
    }

    /**
     * Get a node by ID from the flow definition.
     *
     * @return array<string, mixed>|null
     */
    public function getNode(string $nodeId): ?array
    {
        $nodes = $this->definition['nodes'] ?? [];

        foreach ($nodes as $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Get edges from a specific node, optionally filtered by source handle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEdgesFrom(string $nodeId, ?string $sourceHandle = null): array
    {
        $edges = $this->definition['edges'] ?? [];

        return array_values(array_filter($edges, function ($edge) use ($nodeId, $sourceHandle) {
            if (($edge['source'] ?? null) !== $nodeId) {
                return false;
            }

            if ($sourceHandle !== null && ($edge['sourceHandle'] ?? null) !== $sourceHandle) {
                return false;
            }

            return true;
        }));
    }
}
