<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(AgentTeam::class, 'agent_team_id');
    }
}
