<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\DebateRoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebateRound extends Model
{
    /** @use HasFactory<DebateRoundFactory> */
    use HasFactory, HasPrefixedUlid;

    use UsesTenantConnection;

    protected $fillable = [
        'debate_id',
        'round_number',
        'type',
        'status',
        'consensus_score',
        'consensus_summary',
        'consensus_reached',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'consensus_score' => 'integer',
            'consensus_summary' => 'array',
            'consensus_reached' => 'boolean',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'drnd';
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class, 'debate_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(DebateTurn::class, 'debate_round_id')->orderBy('created_at')->orderBy('id');
    }
}
