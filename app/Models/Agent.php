<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'agent_team_id',
        'type',
        'name',
        'description',
        'status',
        'prompt_template',
        'model',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => AgentType::class,
            'status' => AgentStatus::class,
            'config' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(AgentTeam::class, 'agent_team_id');
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'agent_knowledge_bases')
            ->withTimestamps();
    }

    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'agent_tools')
            ->withTimestamps();
    }

    public function scopeStandalone(Builder $query): Builder
    {
        return $query->whereNull('agent_team_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType(Builder $query, AgentType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isStandalone(): bool
    {
        return $this->agent_team_id === null;
    }
}
