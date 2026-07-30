<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spreadsheet import run, from queued to finished.
 *
 * Counts are kept separate rather than derived at the end because the row is
 * read WHILE the job runs — a progress bar needs the running totals, and a
 * partial import that was interrupted has to be readable as exactly that.
 */
class AppImport extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'object_id',
        'object_name',
        'file_name',
        'status',
        'total_rows',
        'processed',
        'created_count',
        'updated_count',
        'failed_count',
        'errors',
        'error',
        'truncated',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'truncated' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'imp';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /**
     * The shape the panel renders, live and on reload alike.
     *
     * @return array<string, mixed>
     */
    public function toProgress(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'object_name' => $this->object_name,
            'file_name' => $this->file_name,
            'total_rows' => $this->total_rows,
            'processed' => $this->processed,
            'created' => $this->created_count,
            'updated' => $this->updated_count,
            'failed' => $this->failed_count,
            'errors' => $this->errors ?? [],
            'error' => $this->error,
            'truncated' => $this->truncated,
            'finished' => $this->isFinished(),
        ];
    }
}
