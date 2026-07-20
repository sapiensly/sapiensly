<?php

namespace App\Jobs;

use App\Facades\TenantCache;
use App\Models\PlaygroundRun;
use App\Models\User;
use App\Services\Ai\PlaygroundRunner;
use App\Services\AiProviderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;

/**
 * Executes one Playground run off the HTTP worker. The controller persists the
 * run as `queued` and returns immediately; this job flips it to `running`,
 * executes the capability, and lands the terminal state (`ok`|`error`) with
 * output, usage, raw provider payload and timing.
 *
 * Telemetry stays exact: `duration_ms` is measured strictly around the
 * provider execution (queue wait lives in queued_at → started_at), and the
 * full result payload — inline binaries included — is parked in TenantCache
 * for delivery to the browser while the DB row stores binary stubs only.
 */
class ExecutePlaygroundRun implements ShouldQueue
{
    use Queueable;

    /** How long the browser-facing payload (with inline binaries) stays retrievable. */
    public const PAYLOAD_TTL_SECONDS = 900;

    // Above the provider request timeout (ai.request_timeout, 180s) so the
    // HTTP client gives up before the worker is killed mid-write.
    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $runId,
        public int $userId,
        public ?string $modelId,
        public array $input,
        public ?string $filePath = null,
        public ?string $fileName = null,
        public ?string $fileMime = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(PlaygroundRunner $runner, AiProviderService $providers): void
    {
        $run = PlaygroundRun::find($this->runId);
        if ($run === null || $run->isTerminal()) {
            $this->cleanupFile();

            return;
        }

        $run->forceFill(['status' => PlaygroundRun::STATUS_RUNNING, 'started_at' => now()])->save();

        try {
            $user = User::findOrFail($this->userId);
            $providers->applyRuntimeConfig($user);

            $file = $this->filePath !== null
                ? new UploadedFile($this->filePath, $this->fileName ?? basename($this->filePath), $this->fileMime, null, true)
                : null;

            $startedAt = hrtime(true);

            try {
                $result = $runner->execute($user, $run->capability, $this->modelId, $this->input, $file);
                $result['duration_ms'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);

                $this->finish($run, $runner, $result, null);
            } catch (\Throwable $e) {
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

                $this->finish($run, $runner, ['duration_ms' => $durationMs], $e->getMessage());
            }
        } finally {
            $this->cleanupFile();
        }
    }

    /** A worker crash / timeout must not leave the run stuck in `running`. */
    public function failed(?\Throwable $exception): void
    {
        $run = PlaygroundRun::find($this->runId);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => PlaygroundRun::STATUS_ERROR,
            'error' => $exception?->getMessage() ?? 'The run crashed or timed out.',
            'finished_at' => now(),
        ])->save();

        $this->cleanupFile();
    }

    /**
     * Land the terminal state and park the full payload for the browser.
     *
     * @param  array<string, mixed>  $result
     */
    private function finish(PlaygroundRun $run, PlaygroundRunner $runner, array $result, ?string $error): void
    {
        $handler = $runner->lastHandler();
        $output = array_diff_key($result, array_flip(['model', 'driver', 'usage', 'duration_ms', 'text']));

        $run->forceFill([
            'status' => $error === null ? PlaygroundRun::STATUS_OK : PlaygroundRun::STATUS_ERROR,
            'driver' => $handler['driver'] ?? null,
            'model' => $handler['model'] ?? null,
            'output_text' => $result['text'] ?? null,
            'output' => $output !== [] ? PlaygroundRun::stripBinaryPayloads($output) : null,
            'response' => PlaygroundRun::stripBinaryPayloads($result) ?: null,
            'raw' => PlaygroundRun::stripBinaryPayloads($runner->rawResponse()),
            'usage' => $result['usage'] ?? null,
            'error' => $error,
            'duration_ms' => $result['duration_ms'] ?? null,
            'finished_at' => now(),
        ])->save();

        // The verbatim payload (inline images/audio) goes to the tenant cache —
        // the scope was restored by the queue's tenant-context middleware.
        if ($error === null) {
            TenantCache::put($run->payloadCacheKey(), $result, self::PAYLOAD_TTL_SECONDS);
        }
    }

    private function cleanupFile(): void
    {
        if ($this->filePath !== null && is_file($this->filePath)) {
            @unlink($this->filePath);
        }
    }
}
