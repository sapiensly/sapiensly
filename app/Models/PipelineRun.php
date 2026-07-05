<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One L4 Express pipeline execution: the persisted state machine (phase,
 * per-gate telemetry, outcome) that makes a run observable while it happens,
 * auditable after, and safe to reason about when a worker dies mid-flight.
 */
class PipelineRun extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'app_id',
        'conversation_id',
        'kind',
        'status',
        'phase',
        'prompt',
        'gates',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'gates' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'plr';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(BuilderConversation::class, 'conversation_id');
    }

    /**
     * Append one gate's telemetry (name-keyed; a retried gate overwrites).
     *
     * @param  array<string, mixed>  $telemetry
     */
    public function recordGate(string $name, array $telemetry): void
    {
        $gates = $this->gates ?? [];
        $gates[$name] = $telemetry;
        $this->forceFill(['gates' => $gates])->save();
    }
}
