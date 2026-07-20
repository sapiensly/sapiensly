<?php

namespace App\Http\Controllers;

use App\Facades\TenantCache;
use App\Jobs\ExecutePlaygroundRun;
use App\Models\AiCatalogModel;
use App\Models\PlaygroundRun;
use App\Models\User;
use App\Services\Ai\PlaygroundRunner;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Playground — a test suite for every specialized AI capability. Each run
 * uses the capability's configured admin default (or a user-chosen override),
 * is executed by {@see PlaygroundRunner}, and is persisted as a
 * {@see PlaygroundRun} (input, model, sanitized response, timing, usage, raw
 * provider payload) so runs can be reviewed and compared as benchmarks. Binary
 * outputs (images, speech) come back inline as base64 data URLs but are stored
 * only as byte counts.
 */
class PlaygroundController extends Controller
{
    /** Catalog capabilities a user may override-pick across the playground. */
    private const PICKER_CAPABILITIES = ['chat', 'embeddings', 'vision', 'image', 'transcription', 'speech', 'rerank'];

    public function __construct(
        private readonly PlaygroundRunner $runner,
        private readonly AiProviderService $providers,
    ) {}

    public function index(Request $request): Response
    {
        $capabilities = [];
        foreach (PlaygroundRunner::CAPABILITIES as $key => $meta) {
            $model = null;
            try {
                $model = $this->runner->resolveForRun($key, null)['model'];
            } catch (\Throwable) {
                // Unconfigured — the UI shows a "set a default" hint.
            }

            $capabilities[] = [
                'key' => $key,
                'pickerCapability' => $meta['catalog'],
                'input' => $meta['input'],
                'output' => $meta['output'],
                'defaultModel' => $model,
            ];
        }

        return Inertia::render('playground/Index', [
            'capabilities' => $capabilities,
            'modelsByCapability' => $this->modelsByCapability($request->user()),
        ]);
    }

    /**
     * Enqueue a run. Provider latency (up to the 180s request timeout) must
     * never tie up an HTTP worker — a burst of slow runs used to exhaust FPM —
     * so execution happens in {@see ExecutePlaygroundRun} on the `ai` queue and
     * the browser polls {@see status()}. On the sync queue driver the job
     * finishes inline and the terminal payload is returned directly.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => ['required', 'string', Rule::in(array_keys(PlaygroundRunner::CAPABILITIES))],
            'model_id' => ['nullable', Rule::exists('ai_catalog_models', 'id')],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'text' => ['nullable', 'string', 'max:2000'],
            'query' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:100'],
            'documents.*' => ['string', 'max:10000'],
            'file' => ['nullable', 'file', 'max:30720', 'mimes:jpg,jpeg,png,gif,webp,pdf,mp3,wav,m4a,ogg,webm,flac'],
            // Speech generation voice controls.
            'voice' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $input = array_filter([
            'prompt' => $validated['prompt'] ?? null,
            'text' => $validated['text'] ?? null,
            'query' => $validated['query'] ?? null,
            'documents' => ($validated['documents'] ?? []) !== [] ? $validated['documents'] : null,
            'voice' => $validated['voice'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
        ], fn ($v) => $v !== null);

        // Park the upload where the queue worker can read it; the job deletes it.
        $file = $request->file('file');
        $filePath = null;
        if ($file !== null) {
            $stored = $file->store('playground-runs', 'local');
            $filePath = $stored !== false ? Storage::disk('local')->path($stored) : null;
        }

        $run = PlaygroundRun::create([
            'capability' => $validated['capability'],
            'status' => PlaygroundRun::STATUS_QUEUED,
            'input' => $input !== [] ? $input : null,
            'file_meta' => $file !== null ? [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ] : null,
            'queued_at' => now(),
        ]);

        ExecutePlaygroundRun::dispatch(
            $run->id,
            $request->user()->id,
            $validated['model_id'] ?? null,
            $input + ['documents' => $validated['documents'] ?? []],
            $filePath,
            $file?->getClientOriginalName(),
            $file?->getClientMimeType(),
        );

        // Sync driver (tests, queue-less local) finished inline — answer with
        // the terminal payload so the old synchronous API shape survives.
        $run->refresh();
        if ($run->isTerminal()) {
            return $this->runStatePayload($run, useHttpErrorCodes: true);
        }

        return response()->json(['ok' => true, 'run_id' => $run->id, 'status' => $run->status], 202);
    }

    /** Polling endpoint: the run's current state, with the full payload once terminal. */
    public function status(PlaygroundRun $run): JsonResponse
    {
        return $this->runStatePayload($run, useHttpErrorCodes: false);
    }

    /**
     * The run history for the current tenant scope, newest first. RLS limits
     * rows to the caller's organization (or personal scope).
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => ['nullable', 'string', Rule::in(array_keys(PlaygroundRunner::CAPABILITIES))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = PlaygroundRun::query()
            ->when($validated['capability'] ?? null, fn ($q, $capability) => $q->where('capability', $capability))
            ->orderByDesc('created_at')
            // ULIDs are time-ordered — a stable tiebreak for same-second runs.
            ->orderByDesc('id')
            ->paginate(15);

        $page->getCollection()->load('user:id,name');

        return response()->json([
            'data' => $page->getCollection()->map(fn (PlaygroundRun $run) => [
                'id' => $run->id,
                'capability' => $run->capability,
                'driver' => $run->driver,
                'model' => $run->model,
                'served_by' => $run->response['served_by'] ?? null,
                'status' => $run->status,
                'excerpt' => $this->inputExcerpt($run),
                'total_tokens' => $run->usage['total_tokens'] ?? null,
                'cost' => $run->usage['cost'] ?? null,
                'duration_ms' => $run->duration_ms,
                'tokens_per_second' => $run->metrics()['latency']['output_tokens_per_second'],
                'error' => $run->error !== null ? Str::limit($run->error, 140) : null,
                'user' => $run->user?->name,
                'created_at' => $run->created_at?->toIso8601String(),
            ])->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /** Full detail of one run, including the sanitized response and raw provider payload. */
    public function show(PlaygroundRun $run): JsonResponse
    {
        $run->load('user:id,name');

        return response()->json([
            'id' => $run->id,
            'capability' => $run->capability,
            'driver' => $run->driver,
            'model' => $run->model,
            'served_by' => $run->response['served_by'] ?? null,
            'status' => $run->status,
            'input' => $run->input,
            'file_meta' => $run->file_meta,
            'output_text' => $run->output_text,
            'output' => $run->output,
            'response' => $run->response,
            'raw' => $run->raw,
            'usage' => $run->usage,
            'error' => $run->error,
            'duration_ms' => $run->duration_ms,
            'ttft_ms' => $run->ttft_ms,
            'queue_wait_ms' => $run->queueWaitMs(),
            'metrics' => $run->metrics(),
            'queued_at' => $run->queued_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'user' => $run->user?->name,
            'created_at' => $run->created_at?->toIso8601String(),
        ]);
    }

    /**
     * The JSON shape for a run's current state. While in flight it is just
     * `{ok, run_id, status}`; once terminal it carries the full result — from
     * TenantCache while the inline-binary payload is still parked there, else
     * the stored row (binaries reduced to stubs).
     */
    private function runStatePayload(PlaygroundRun $run, bool $useHttpErrorCodes): JsonResponse
    {
        if (! $run->isTerminal()) {
            return response()->json(['ok' => true, 'run_id' => $run->id, 'status' => $run->status]);
        }

        if ($run->status === PlaygroundRun::STATUS_ERROR) {
            return response()->json([
                'ok' => false,
                'run_id' => $run->id,
                'status' => $run->status,
                'error' => $run->error,
                'duration_ms' => $run->duration_ms,
            ], $useHttpErrorCodes ? 422 : 200);
        }

        $payload = TenantCache::get($run->payloadCacheKey());
        if (! is_array($payload)) {
            $payload = $run->response ?? array_filter([
                'model' => $run->model,
                'driver' => $run->driver,
                'usage' => $run->usage,
                'text' => $run->output_text,
                'duration_ms' => $run->duration_ms,
            ], fn ($v) => $v !== null) + ($run->output ?? []);
        }

        return response()->json(
            ['ok' => true, 'run_id' => $run->id, 'status' => $run->status, 'metrics' => $run->metrics()] + $payload
        );
    }

    /** A short human label for a run's input: the prompt/text/query, else the file name. */
    private function inputExcerpt(PlaygroundRun $run): ?string
    {
        $text = $run->input['prompt'] ?? $run->input['text'] ?? $run->input['query'] ?? $run->file_meta['name'] ?? null;

        return $text !== null ? Str::limit((string) $text, 120) : null;
    }

    /**
     * Enabled catalog models grouped by the capabilities the playground can
     * pick — restricted to drivers that actually have a usable key (the
     * platform-wide global key, or the tenant's own BYOK provider), so the
     * picker never offers a model that would fail at inference time.
     *
     * @return array<string, array<int, array{id: string, driver: string, name: string, label: string}>>
     */
    private function modelsByCapability(User $user): array
    {
        $rows = AiCatalogModel::query()
            ->whereIn('capability', self::PICKER_CAPABILITIES)
            ->where('is_enabled', true)
            ->orderBy('driver')
            ->orderBy('label')
            ->get(['id', 'driver', 'model_id', 'label', 'capability']);

        $byokDrivers = $this->providers->getProvidersForContext($user)
            ->pluck('driver')
            ->flip()
            ->all();

        $usableDrivers = [];
        $out = array_fill_keys(self::PICKER_CAPABILITIES, []);
        foreach ($rows as $row) {
            $usableDrivers[$row->driver] ??= isset($byokDrivers[$row->driver])
                || $this->providers->isDriverConfigured($row->driver);
            if (! $usableDrivers[$row->driver]) {
                continue;
            }

            $out[$row->capability][] = [
                'id' => (string) $row->id,
                'driver' => $row->driver,
                'name' => $row->model_id,
                'label' => $row->label,
            ];
        }

        return $out;
    }
}
