<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Playground\GetPlaygroundBenchmarkTool;
use App\Models\PlaygroundBenchmark;
use App\Models\PlaygroundRun;
use App\Models\User;

/**
 * Benchmark comparison over MCP: the interpreted multi-model comparison —
 * verdicts, medians, winner and member runs — fetched by the pgbench_... id the
 * Playground UI shows, so an external AI can audit a decision end to end.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * @param  array<string, mixed>  $runAttrs
 */
function benchmarkWithRuns(User $owner): PlaygroundBenchmark
{
    $benchmark = new PlaygroundBenchmark;
    $benchmark->forceFill([
        'organization_id' => $owner->organization_id,
        'user_id' => $owner->id,
        'capability' => 'text',
        'input' => ['prompt' => 'Compare these models'],
        'repeats' => 1,
    ])->save();

    foreach ([
        ['model' => 'model-fast', 'duration_ms' => 1000, 'cost' => 0.02, 'text' => 'Fast answer'],
        ['model' => 'model-cheap', 'duration_ms' => 4000, 'cost' => 0.001, 'text' => 'Cheap answer'],
    ] as $spec) {
        $run = new PlaygroundRun;
        $run->forceFill([
            'benchmark_id' => $benchmark->id,
            'organization_id' => $owner->organization_id,
            'user_id' => $owner->id,
            'capability' => 'text',
            'driver' => 'anthropic',
            'model' => $spec['model'],
            'status' => PlaygroundRun::STATUS_OK,
            'input' => ['prompt' => 'Compare these models'],
            'output_text' => $spec['text'],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 100, 'total_tokens' => 110, 'cost' => $spec['cost']],
            'duration_ms' => $spec['duration_ms'],
        ])->save();
    }

    return $benchmark;
}

it('returns the interpreted comparison with verdicts and member runs', function () {
    $benchmark = benchmarkWithRuns($this->user);

    $winner = $benchmark->runs()->where('model', 'model-cheap')->firstOrFail();
    $benchmark->forceFill([
        'winner_run_id' => $winner->id,
        'decision_note' => 'Cheap wins for batch work.',
    ])->save();

    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundBenchmarkTool::class, ['benchmark_id' => $benchmark->id])
        ->assertOk()
        ->assertSee($benchmark->id)
        ->assertSee('Compare these models')
        ->assertSee('"status":"complete"')
        // Verdicts: fastest by execution, cheapest by cost.
        ->assertSee('fastest_execution')
        ->assertSee('model-fast')
        ->assertSee('model-cheap')
        // The human decision travels with the data.
        ->assertSee('Cheap wins for batch work.')
        // Member runs expose their pgrun_ ids for drill-down.
        ->assertSee('pgrun_');
});

it('rejects a benchmark from another tenant', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $benchmark = benchmarkWithRuns($other);

    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundBenchmarkTool::class, ['benchmark_id' => $benchmark->id])
        ->assertSee('is visible to you');
});

it('rejects an unknown benchmark id', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundBenchmarkTool::class, ['benchmark_id' => 'pgbench_nope'])
        ->assertSee('is visible to you');
});
