<?php

namespace App\Services\Workflows;

use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;

/**
 * Executes a user-authored JavaScript snippet in an isolated QuickJS (WASM)
 * sandbox by shelling out to a short-lived Node process — the same one-shot
 * model as an http.request, no persistent daemon.
 *
 * The Node runner (resources/sandbox/quickjs-runner.mjs) is the real security
 * boundary: the isolate has no network, filesystem or host access, and the
 * runner enforces hard wall-clock and memory caps. PHP adds its own slightly
 * larger process timeout as a backstop and refuses to read more than a bounded
 * amount of output.
 */
class ScriptRunner
{
    /** Hard ceiling on the script's own wall-clock budget (ms). */
    private const MAX_TIMEOUT_MS = 10000;

    /** Heap cap handed to the isolate (bytes). */
    private const MEMORY_BYTES = 64 * 1024 * 1024;

    public function __construct(private ?ProcessFactory $process = null) {}

    /**
     * Run $code with $input bound as the `input` argument and return its
     * JSON-decoded return value.
     *
     * @throws StepFailedException on a non-zero exit, a timeout, malformed
     *                             output, or an error raised inside the script.
     */
    public function run(string $code, mixed $input = null, int $timeoutMs = 2000): mixed
    {
        $timeoutMs = max(50, min($timeoutMs, self::MAX_TIMEOUT_MS));

        $payload = json_encode([
            'code' => $code,
            'input' => $input,
            'timeout_ms' => $timeoutMs,
            'memory_bytes' => self::MEMORY_BYTES,
        ], JSON_THROW_ON_ERROR);

        $runner = resource_path('sandbox/quickjs-runner.mjs');
        $node = (string) config('services.node.binary', 'node');
        $processTimeout = (int) ceil($timeoutMs / 1000) + 5;

        $result = ($this->process ?? Process::getFacadeRoot())
            ->timeout($processTimeout)
            ->input($payload)
            ->run([$node, $runner]);

        if (! $result->successful()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());

            throw new StepFailedException('script sandbox failed: '.($detail !== '' ? $detail : 'exit code '.$result->exitCode()));
        }

        $decoded = json_decode(trim($result->output()), true);
        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            throw new StepFailedException('script sandbox returned malformed output');
        }

        if ($decoded['ok'] !== true) {
            throw new StepFailedException('script error: '.($decoded['error'] ?? 'unknown'));
        }

        return $decoded['value'] ?? null;
    }
}
