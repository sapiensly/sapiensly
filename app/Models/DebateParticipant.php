<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Database\Factories\DebateParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebateParticipant extends Model
{
    /** @use HasFactory<DebateParticipantFactory> */
    use HasFactory, HasPrefixedUlid;

    protected $fillable = [
        'debate_id',
        'model',
        'provider',
        'display_name',
        'position',
        'accent',
        'final_stance',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'dpar';
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class, 'debate_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(DebateTurn::class, 'debate_participant_id');
    }
}
