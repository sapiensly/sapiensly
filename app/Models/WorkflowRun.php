<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    use HasPrefixedUlid;

    protected $fillable = [
        'organization_id',
        'app_id',
        'workflow_id',
        'trigger_type',
        'trigger_payload',
        'status',
        'variables',
        'error',
        'triggered_by_user_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_payload' => 'array',
            'variables' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'wrun';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class, 'run_id')->orderBy('sequence_index');
    }
}
