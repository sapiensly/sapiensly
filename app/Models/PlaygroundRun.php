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

    /** Wall-clock milliseconds from enqueue to terminal state (queue wait + execution + job overhead). */
    public function endToEndMs(): ?int
    {
        if ($this->queued_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) abs($this->finished_at->diffInMilliseconds($this->queued_at));
    }

    /**
     * Derived latency, cost and efficiency metrics for one run — the values a
     * consumer would otherwise have to compute from `usage` and the raw provider
     * payload. Every field is null when its inputs are absent (a failed run, a
     * provider that reports no cost, a non-reasoning model) rather than guessed,
     * so `null` always means "not measurable", never zero.
     *
     * @return array{
     *     latency: array{queue_wait_ms: int|null, execution_ms: int|null, end_to_end_ms: int|null, job_overhead_ms: int|null, output_tokens_per_second: float|null},
     *     cost: array{total: float|null, estimated: bool, per_1k_tokens: float|null, input: float|null, output: float|null, per_useful_output_token: float|null},
     *     efficiency: array{prompt_tokens: int|null, completion_tokens: int|null, reasoning_tokens: int|null, reasoning_ratio: float|null, cached_prompt_tokens: int|null, cached_prompt_ratio: float|null}
     * }
     */
    public function metrics(): array
    {
        $usage = $this->usage ?? [];
        $promptTokens = $this->intOrNull($usage['prompt_tokens'] ?? null);
        $completionTokens = $this->intOrNull($usage['completion_tokens'] ?? null);
        $totalTokens = $this->intOrNull($usage['total_tokens'] ?? null);
        $cost = isset($usage['cost']) ? (float) $usage['cost'] : null;

        // Provider-specific breakdowns live in the raw payload (e.g. OpenRouter);
        // absent for providers that don't report them.
        $reasoningTokens = $this->intOrNull(data_get($this->raw, 'usage.completion_tokens_details.reasoning_tokens'));
        $cachedPromptTokens = $this->intOrNull(data_get($this->raw, 'usage.prompt_tokens_details.cached_tokens'));
        $inputCost = $this->floatOrNull(data_get($this->raw, 'usage.cost_details.upstream_inference_prompt_cost'));
        $outputCost = $this->floatOrNull(data_get($this->raw, 'usage.cost_details.upstream_inference_completions_cost'));

        $usefulOutputTokens = $completionTokens !== null && $reasoningTokens !== null
            ? max(0, $completionTokens - $reasoningTokens)
            : $completionTokens;

        return [
            'latency' => [
                'queue_wait_ms' => $this->queueWaitMs(),
                'execution_ms' => $this->duration_ms,
                'end_to_end_ms' => $this->endToEndMs(),
                'job_overhead_ms' => $this->jobOverheadMs(),
                'output_tokens_per_second' => $completionTokens !== null && $this->duration_ms !== null && $this->duration_ms > 0
                    ? round($completionTokens / ($this->duration_ms / 1000), 1)
                    : null,
            ],
            'cost' => [
                'total' => $cost,
                'estimated' => (bool) ($usage['estimated'] ?? false),
                'per_1k_tokens' => $cost !== null && $totalTokens !== null && $totalTokens > 0
                    ? round($cost / $totalTokens * 1000, 6)
                    : null,
                'input' => $inputCost,
                'output' => $outputCost,
                'per_useful_output_token' => $cost !== null && $usefulOutputTokens !== null && $usefulOutputTokens > 0
                    ? round($cost / $usefulOutputTokens, 8)
                    : null,
            ],
            'efficiency' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'reasoning_tokens' => $reasoningTokens,
                'reasoning_ratio' => $reasoningTokens !== null && $completionTokens !== null && $completionTokens > 0
                    ? round($reasoningTokens / $completionTokens, 3)
                    : null,
                'cached_prompt_tokens' => $cachedPromptTokens,
                'cached_prompt_ratio' => $cachedPromptTokens !== null && $promptTokens !== null && $promptTokens > 0
                    ? round($cachedPromptTokens / $promptTokens, 3)
                    : null,
            ],
        ];
    }

    /** Job time not spent in the provider call: config apply, persistence, cache write. */
    private function jobOverheadMs(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null || $this->duration_ms === null) {
            return null;
        }

        $jobMs = (int) abs($this->finished_at->diffInMilliseconds($this->started_at));

        return max(0, $jobMs - $this->duration_ms);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
