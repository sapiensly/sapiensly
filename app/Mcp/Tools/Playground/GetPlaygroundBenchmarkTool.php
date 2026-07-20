<?php

namespace App\Mcp\Tools\Playground;

use App\Mcp\Tools\SapiensTool;
use App\Models\PlaygroundBenchmark;
use App\Models\PlaygroundRun;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Full detail of one Playground benchmark by its id (pgbench_...): the shared prompt, the interpreted comparison across models (per-model median metrics, per-dimension verdicts — fastest execution, best TTFT, cheapest, highest throughput — and the human-chosen winner with its note), plus every member run with its metrics, answer text and pgrun_... id for drill-down via get_playground_run. The id is shown next to each benchmark in the Playground UI.')]
class GetPlaygroundBenchmarkTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'benchmark_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // RLS already limits rows to the caller's tenant scope; mirror the
        // predicate as defense-in-depth (and for the owner connection in tests).
        $benchmark = PlaygroundBenchmark::query()
            ->when(
                $user->organization_id !== null,
                fn ($q) => $q->where('organization_id', $user->organization_id),
                fn ($q) => $q->where('user_id', $user->id)->whereNull('organization_id'),
            )
            ->find($validated['benchmark_id']);

        if ($benchmark === null) {
            return Response::error("No Playground benchmark '{$validated['benchmark_id']}' is visible to you.");
        }

        $benchmark->load('runs', 'user:id,name');

        return Response::json([
            'id' => $benchmark->id,
            'capability' => $benchmark->capability,
            'input' => $benchmark->input,
            'repeats' => $benchmark->repeats,
            'user' => $benchmark->user?->name,
            'created_at' => $benchmark->created_at?->toIso8601String(),
            'comparison' => $benchmark->comparison(),
            'runs' => $benchmark->runs->map(fn (PlaygroundRun $run) => [
                'id' => $run->id,
                'model' => $run->model,
                'driver' => $run->driver,
                'served_by' => $run->response['served_by'] ?? null,
                'status' => $run->status,
                'output_text' => $run->output_text,
                'error' => $run->error,
                'metrics' => $run->metrics(),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'benchmark_id' => $schema->string()->description('The Playground benchmark id (pgbench_...).')->required(),
        ];
    }
}
