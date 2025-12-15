<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentTeam extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'description',
        'status',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'visibility' => Visibility::class,
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'team';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function triageAgent(): HasOne
    {
        return $this->hasOne(Agent::class)->where('type', AgentType::Triage);
    }

    public function knowledgeAgent(): HasOne
    {
        return $this->hasOne(Agent::class)->where('type', AgentType::Knowledge);
    }

    public function actionAgent(): HasOne
    {
        return $this->hasOne(Agent::class)->where('type', AgentType::Action);
    }
}
