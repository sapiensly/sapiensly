<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One Playground benchmark: a single prompt run against N models so their
 * latency / cost / efficiency metrics — and their answers — can be compared
 * side by side. The row is the group header (shared input, repeats, the human
 * verdict); all telemetry lives on the member {@see PlaygroundRun}s, which
 * point back via benchmark_id.
 *
 * {@see comparison()} is the single interpretation of the results, consumed by
 * both the Playground UI and the MCP tool, so an external AI reading a
 * pgbench_... id sees exactly what the humans saw.
 */
class PlaygroundBenchmark extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'capability',
        'input',
        'repeats',
        'winner_run_id',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'repeats' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'pgbench';
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PlaygroundRun::class, 'benchmark_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether every member run reached a terminal state. */
    public function isTerminal(): bool
    {
        return $this->runs->every(fn (PlaygroundRun $run) => $run->isTerminal());
    }

    /**
     * The comparative read of the benchmark: per-model aggregated metrics
     * (median over ok repeats), per-dimension verdicts, and cost/latency deltas
     * relative to the best. Latency comparisons use execution_ms / ttft_ms only
     * — never queue wait, which reflects worker availability, not the model.
     *
     * @return array{
     *     status: string,
     *     models: list<array<string, mixed>>,
     *     verdicts: array<string, array{model: string, value: float|int}|null>,
     *     winner: array{run_id: string, model: string|null, note: string|null}|null
     * }
     */
    public function comparison(): array
    {
        $models = $this->runs
            ->groupBy(fn (PlaygroundRun $run) => $run->model ?? 'unresolved')
            ->map(fn ($runs, string $model) => $this->modelEntry($model, $runs))
            ->values()
            ->all();

        return [
            'status' => $this->isTerminal() ? 'complete' : 'running',
            'models' => $models,
            'verdicts' => $this->verdicts($models),
            'winner' => $this->winnerEntry(),
        ];
    }

    /**
     * @param  Collection<int, PlaygroundRun>  $runs
     * @return array<string, mixed>
     */
    private function modelEntry(string $model, $runs): array
    {
        $ok = $runs->filter(fn (PlaygroundRun $run) => $run->status === PlaygroundRun::STATUS_OK);
        $metricSets = $ok->map(fn (PlaygroundRun $run) => $run->metrics());

        $median = fn (string ...$path) => $this->median(
            $metricSets->map(fn (array $m) => data_get($m, implode('.', $path)))->filter(fn ($v) => $v !== null)->values()->all()
        );

        $status = match (true) {
            $runs->contains(fn (PlaygroundRun $run) => ! $run->isTerminal()) => 'running',
            $ok->isEmpty() => 'error',
            default => 'ok',
        };

        return [
            'model' => $model,
            'driver' => $runs->first()->driver,
            'served_by' => $ok->first()?->response['served_by'] ?? null,
            'status' => $status,
            'error' => $runs->firstWhere('status', PlaygroundRun::STATUS_ERROR)?->error,
            'run_ids' => $runs->pluck('id')->all(),
            'runs_ok' => $ok->count(),
            'runs_total' => $runs->count(),
            // Median over ok repeats — a single run's value when repeats = 1.
            'metrics' => [
                'execution_ms' => $median('latency', 'execution_ms'),
                'ttft_ms' => $median('latency', 'ttft_ms'),
                'output_tokens_per_second' => $median('latency', 'output_tokens_per_second'),
                'cost' => $median('cost', 'total'),
                'per_1k_tokens' => $median('cost', 'per_1k_tokens'),
                'total_tokens' => $median('efficiency', 'total_tokens'),
                'completion_tokens' => $median('efficiency', 'completion_tokens'),
                'reasoning_ratio' => $median('efficiency', 'reasoning_ratio'),
                'cached_prompt_ratio' => $median('efficiency', 'cached_prompt_ratio'),
            ],
        ];
    }

    /**
     * Per-dimension winners across models with a measurable value. Each verdict
     * names the model and its winning value; null when no model measured it.
     *
     * @param  list<array<string, mixed>>  $models
     * @return array<string, array{model: string, value: float|int}|null>
     */
    private function verdicts(array $models): array
    {
        $ok = array_values(array_filter($models, fn (array $m) => $m['status'] === 'ok'));

        $best = function (string $metric, bool $lowest) use ($ok): ?array {
            $candidates = array_values(array_filter($ok, fn (array $m) => $m['metrics'][$metric] !== null));
            if ($candidates === []) {
                return null;
            }

            usort($candidates, fn (array $a, array $b) => $lowest
                ? $a['metrics'][$metric] <=> $b['metrics'][$metric]
                : $b['metrics'][$metric] <=> $a['metrics'][$metric]);

            return ['model' => $candidates[0]['model'], 'value' => $candidates[0]['metrics'][$metric]];
        };

        return [
            'fastest_execution' => $best('execution_ms', lowest: true),
            'best_ttft' => $best('ttft_ms', lowest: true),
            'cheapest' => $best('cost', lowest: true),
            'highest_throughput' => $best('output_tokens_per_second', lowest: false),
        ];
    }

    /** @return array{run_id: string, model: string|null, note: string|null}|null */
    private function winnerEntry(): ?array
    {
        if ($this->winner_run_id === null) {
            return null;
        }

        $run = $this->runs->firstWhere('id', $this->winner_run_id);

        return [
            'run_id' => $this->winner_run_id,
            'model' => $run?->model,
            'note' => $this->decision_note,
        ];
    }

    /** @param list<int|float> $values */
    private function median(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return is_float($median) ? round($median, 6) : $median;
    }
}
