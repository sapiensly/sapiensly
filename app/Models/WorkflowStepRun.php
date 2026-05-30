<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStepRun extends Model
{
    use HasPrefixedUlid;

    protected $fillable = [
        'run_id',
        'step_id',
        'step_type',
        'status',
        'sequence_index',
        'input',
        'output',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'sequence_index' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'wstep';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }
}
