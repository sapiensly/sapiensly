<?php

namespace App\Models;

use App\Jobs\ExecutePlaygroundRun;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One persisted Playground run: the prompt/input tested, the model that ran
 * it, the (sanitized) response, timing, token usage and the raw provider
 * payload when the transport exposes it. The history behind the Playground's
 * test-suite / benchmark features.
 *
 * A run is an async state machine — `queued` → `running` → `ok`|`error` —
 * executed by {@see ExecutePlaygroundRun} so provider latency never
 * ties up an HTTP worker. `duration_ms` measures only the provider execution;
 * queue wait is queued_at → started_at.
 */
class PlaygroundRun extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'capability',
        'driver',
        'model',
        'status',
        'input',
        'file_meta',
        'output_text',
        'output',
        'response',
        'raw',
        'usage',
        'error',
        'duration_ms',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'file_meta' => 'array',
            'output' => 'array',
            'response' => 'array',
            'raw' => 'array',
            'usage' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'pgrun';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_OK, self::STATUS_ERROR], true);
    }

    /** Milliseconds the run waited in the queue before a worker picked it up. */
    public function queueWaitMs(): ?int
    {
        if ($this->queued_at === null || $this->started_at === null) {
            return null;
        }

        return (int) abs($this->started_at->diffInMilliseconds($this->queued_at));
    }

    /**
     * TenantCache key holding the full result payload (inline binaries
     * included) for delivery to the browser; the DB row keeps only stubs.
     */
    public function payloadCacheKey(): string
    {
        return 'playground:run:'.$this->id.':payload';
    }

    /**
     * Replace inline base64 data URLs (generated images/speech, whole request
     * files echoed back by providers) with a size stub so stored rows stay
     * small while every other field of the payload survives verbatim.
     */
    public static function stripBinaryPayloads(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::stripBinaryPayloads(...), $value);
        }

        if (is_string($value) && str_starts_with($value, 'data:') && strlen($value) > 512) {
            $mime = substr($value, 5, max(0, (int) strcspn($value, ';,', 5)));

            return sprintf('[binary %s — %d bytes omitted]', $mime !== '' ? $mime : 'payload', strlen($value));
        }

        return $value;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
