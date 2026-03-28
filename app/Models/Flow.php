<?php

namespace App\Models;

use App\Enums\FlowStatus;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Flow extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility;

    protected $fillable = [
        'user_id',
        'organization_id',
        'agent_id',
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
            'status' => FlowStatus::class,
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FlowStatus::Active);
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
