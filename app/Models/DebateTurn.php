<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\DebateTurnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebateTurn extends Model
{
    /** @use HasFactory<DebateTurnFactory> */
    use HasFactory, HasPrefixedUlid;

    use UsesTenantConnection;

    protected $fillable = [
        'debate_id',
        'debate_round_id',
        'debate_participant_id',
        'role',
        'model',
        'content',
        'stance_summary',
        'status',
        'error',
    ];

    public static function getIdPrefix(): string
    {
        return 'dtrn';
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class, 'debate_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(DebateRound::class, 'debate_round_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(DebateParticipant::class, 'debate_participant_id');
    }
}
