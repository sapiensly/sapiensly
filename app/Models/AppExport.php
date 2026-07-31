<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued export run, and the file it produced.
 *
 * The row records the ROLE the export ran as, not just who asked. A finished
 * file is a frozen answer to "what could that role see", and handing it to
 * someone else later would serve rows their own role never narrowed for them.
 */
class AppExport extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    /** How long a finished file stays downloadable. */
    public const TTL_HOURS = 24;

    protected $fillable = [
        'organization_id',
        'app_id',
        'object_id',
        'object_name',
        'format',
        'role_slug',
        'requested_by_user_id',
        'status',
        'rows_written',
        'disk',
        'storage_path',
        'file_name',
        'size_bytes',
        'error',
        'expires_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'exp';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /** Ready AND still within its window — both, or the file is not served. */
    public function isDownloadable(): bool
    {
        return $this->status === 'completed'
            && $this->storage_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgress(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'format' => $this->format,
            'object_name' => $this->object_name,
            'rows' => $this->rows_written,
            'file_name' => $this->file_name,
            'size_bytes' => $this->size_bytes,
            'error' => $this->error,
            'finished' => $this->isFinished(),
            'downloadable' => $this->isDownloadable(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
