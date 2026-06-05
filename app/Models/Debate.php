<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\DebateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Debate extends Model
{
    /** @use HasFactory<DebateFactory> */
    use HasFactory, HasPrefixedUlid, HasVisibility;

    use UsesTenantConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'title',
        'topic',
        'status',
        'max_rounds',
        'current_round',
        'moderator_model',
        'consensus_reached',
        'consensus_score',
        'settings',
        'visibility',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'max_rounds' => 'integer',
            'current_round' => 'integer',
            'consensus_reached' => 'boolean',
            'consensus_score' => 'integer',
            'settings' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'dbt';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(DebateParticipant::class, 'debate_id')->orderBy('position');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(DebateRound::class, 'debate_id')->orderBy('round_number');
    }

    public function turns(): HasManyThrough
    {
        return $this->hasManyThrough(DebateTurn::class, DebateRound::class, 'debate_id', 'debate_round_id');
    }
}
