<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tool extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'description',
        'config',
        'status',
        'is_validated',
        'last_validated_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ToolType::class,
            'status' => AgentStatus::class,
            'config' => 'array',
            'is_validated' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_tools')
            ->withTimestamps();
    }

    public function groupItems(): HasMany
    {
        return $this->hasMany(ToolGroupItem::class, 'tool_group_id');
    }

    public function memberOf(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'tool_group_items', 'tool_id', 'tool_group_id');
    }

    public function scopeOfType(Builder $query, ToolType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AgentStatus::Active);
    }

    public function isGroup(): bool
    {
        return $this->type === ToolType::Group;
    }

    public function markAsValidated(): void
    {
        $this->update([
            'is_validated' => true,
            'last_validated_at' => now(),
        ]);
    }
}
