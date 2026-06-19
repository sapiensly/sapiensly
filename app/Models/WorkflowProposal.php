<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gated workflow write awaiting a human decision (FR-5.3/9.3). Holds the
 * parametrized action to execute and a legible effect preview; an approver runs
 * it through the shared write path or dismisses it. `pending` until resolved.
 */
class WorkflowProposal extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'workflow_id',
        'run_id',
        'step_id',
        'effect',
        'action',
        'preview',
        'status',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'whp';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }
}
