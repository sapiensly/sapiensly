<?php

namespace App\Mcp\Tools\Playground;

use App\Mcp\Tools\SapiensTool;
use App\Models\PlaygroundRun;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Full trace of one Playground run by its id (pgrun_...): the exact input tested, model/driver that served it, sanitized response, raw provider payload (inline binaries reduced to size stubs), token usage + cost, lifecycle timing (queue wait vs execution) and the error when it failed. Includes a `metrics` block with derived latency (end-to-end, job overhead, output tokens/sec), cost (per-1k-tokens, input/output split — output further split into reasoning vs answer when the model reasoned — cost per useful output token) and efficiency (reasoning + cached-prompt ratios). The id is shown next to each run in the Playground UI.')]
class GetPlaygroundRunTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'run_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // RLS already limits rows to the caller's tenant scope; this mirrors the
        // same predicate in the query as defense-in-depth (and for the owner
        // connection used in tests, where RLS is bypassed).
        $run = PlaygroundRun::query()
            ->when(
                $user->organization_id !== null,
                fn ($q) => $q->where('organization_id', $user->organization_id),
                fn ($q) => $q->where('user_id', $user->id)->whereNull('organization_id'),
            )
            ->find($validated['run_id']);

        if ($run === null) {
            return Response::error("No Playground run '{$validated['run_id']}' is visible to you.");
        }

        $run->load('user:id,name');

        return Response::json([
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
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->description('The Playground run id (pgrun_...).')->required(),
        ];
    }
}
