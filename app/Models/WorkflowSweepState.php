<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cursor for a time-based trigger's sweep. One row per workflow_id; the sweep
 * command advances `last_swept_at` so each record's target moment fires exactly
 * once. Platform schema (no RLS) — see the migration.
 *
 * @property string $id
 * @property string $app_id
 * @property string $workflow_id
 * @property Carbon $last_swept_at
 */
class WorkflowSweepState extends Model
{
    use HasPrefixedUlid;
    use UsesPlatformConnection;

    protected $fillable = [
        'app_id',
        'workflow_id',
        'last_swept_at',
    ];

    public static function getIdPrefix(): string
    {
        return 'sweep';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_swept_at' => 'datetime',
        ];
    }
}
