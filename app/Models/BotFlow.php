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
        'chatbot_id',
        'whatsapp_connection_id',
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

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function whatsAppConnection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
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

    /**
     * Create the blank Bot Flow an AI Bot owns from creation: a lone start node,
     * ready for the author to drop agent and dialog nodes onto.
     */
    public static function blankForChatbot(Chatbot $chatbot): self
    {
        return self::blankForOwner(['chatbot_id' => $chatbot->id], $chatbot->user_id, $chatbot->organization_id, $chatbot->visibility, $chatbot->name);
    }

    /**
     * The blank Bot Flow a WhatsApp connection owns from creation.
     */
    public static function blankForWhatsApp(WhatsAppConnection $connection): self
    {
        $channel = $connection->channel;

        return self::blankForOwner(
            ['whatsapp_connection_id' => $connection->id],
            $channel?->user_id,
            $channel?->organization_id,
            $channel?->visibility ?? Visibility::Private,
            $channel?->name ?? 'WhatsApp',
        );
    }

    /**
     * @param  array<string, string>  $owner
     */
    private static function blankForOwner(array $owner, ?int $userId, ?string $organizationId, Visibility $visibility, string $name): self
    {
        return self::create([
            ...$owner,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'visibility' => $visibility,
            'name' => $name,
            'status' => BotFlowStatus::Draft,
            'definition' => [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 250, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ],
                'edges' => [],
            ],
        ]);
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
