<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility;
    use UsesPlatformConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'type',
        'name',
        'description',
        'keywords',
        'status',
        'visibility',
        'prompt_template',
        'model',
        'config',
        'web_search',
    ];

    protected function casts(): array
    {
        return [
            'type' => AgentType::class,
            'status' => AgentStatus::class,
            'visibility' => Visibility::class,
            'keywords' => 'array',
            'config' => 'array',
            'web_search' => 'boolean',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'agent';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pivot rows linking this agent to knowledge bases.
     *
     * The agent and this pivot live in `platform`; the knowledge base lives in
     * `tenant`. A cross-schema `belongsToMany` join cannot resolve on either
     * runtime connection, so the link rows are read/written through this
     * platform-bound relation and the knowledge bases are resolved separately
     * (see {@see self::knowledgeBaseIds()} / {@see self::loadKnowledgeBases()}).
     */
    public function knowledgeBaseLinks(): HasMany
    {
        return $this->hasMany(AgentKnowledgeBase::class, 'agent_id');
    }

    /**
     * The ids of the knowledge bases attached to this agent (read from the
     * platform-side link table, no cross-schema join).
     *
     * @return array<int, string>
     */
    public function knowledgeBaseIds(): array
    {
        return $this->knowledgeBaseLinks()->pluck('knowledge_base_id')->all();
    }

    /**
     * Replace the agent's knowledge base attachments with the given ids,
     * mirroring belongsToMany::sync() but operating on the platform-bound link
     * table only.
     *
     * @param  array<int, string>  $knowledgeBaseIds
     */
    public function syncKnowledgeBases(array $knowledgeBaseIds): void
    {
        $ids = array_values(array_unique($knowledgeBaseIds));

        $this->knowledgeBaseLinks()->whereNotIn('knowledge_base_id', $ids)->delete();

        $existing = $this->knowledgeBaseLinks()->pluck('knowledge_base_id')->all();

        foreach (array_diff($ids, $existing) as $id) {
            $this->knowledgeBaseLinks()->create(['knowledge_base_id' => $id]);
        }
    }

    /**
     * Resolve and hydrate the `knowledgeBases` relation from the link table, so
     * callers (and JSON serialization) see the familiar shape. The pivot is read
     * on the platform connection and the knowledge bases on the tenant connection.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, KnowledgeBase>
     */
    public function loadKnowledgeBases(array $columns = ['id', 'name']): Collection
    {
        $ids = $this->knowledgeBaseIds();

        $knowledgeBases = empty($ids)
            ? new Collection
            : KnowledgeBase::query()->whereIn('id', $ids)->get($columns);

        $this->setRelation('knowledgeBases', $knowledgeBases);

        return $knowledgeBases;
    }

    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'agent_tools')
            ->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeOfType(Builder $query, AgentType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Every agent is standalone now that teams are dissolved; kept as a no-op
     * pass-through so call sites that filter for standalone agents still work.
     */
    public function scopeStandalone(Builder $query): Builder
    {
        return $query;
    }
}
