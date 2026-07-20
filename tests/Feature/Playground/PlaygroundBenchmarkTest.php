<?php

use App\Models\AiCatalogModel;
use App\Models\PlaygroundBenchmark;
use App\Models\PlaygroundRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

/**
 * Playground benchmarks: one prompt against N models, compared side by side.
 * The comparison (per-model medians, verdicts, winner) is computed server-side
 * by PlaygroundBenchmark::comparison() so the UI and the MCP tool read the
 * exact same interpretation.
 */
function benchUser(): User
{
    return User::factory()->create();
}

function benchModel(string $modelId, float $inputPrice, float $outputPrice): AiCatalogModel
{
    $model = AiCatalogModel::firstOrCreate(
        ['driver' => 'anthropic', 'model_id' => $modelId, 'capability' => 'chat'],
        [
            'label' => $modelId,
            'is_enabled' => true,
            'sort_order' => 0,
            'input_price_per_mtok' => $inputPrice,
            'output_price_per_mtok' => $outputPrice,
        ],
    );
    Cache::forget('ai_pricing_map');

    return $model;
}

/**
 * Fake per-model responses: the cheap model answers briefly, the pricey one
 * at length — so cost/throughput verdicts are deterministic.
 */
function fakeBenchAgents(): void
{
    Ai::fakeAgent(AnonymousAgent::class, function ($prompt, $attachments, $provider, $model) {
        return $model === 'claude-cheap'
            ? new TextResponse('Short answer.', new Usage(promptTokens: 100, completionTokens: 100), new Meta('anthropic', $model))
            : new TextResponse('A much longer, fancier answer.', new Usage(promptTokens: 100, completionTokens: 1000), new Meta('anthropic', $model));
    });
}

test('a benchmark runs the prompt against every model and reports verdicts', function () {
    $cheap = benchModel('claude-cheap', 1, 5);
    $pricey = benchModel('claude-pricey', 10, 50);
    fakeBenchAgents();

    $res = $this->actingAs(benchUser())
        ->post('/playground/benchmark', [
            'capability' => 'text',
            'prompt' => 'Compare me',
            'model_ids' => [$cheap->id, $pricey->id],
        ])
        ->assertStatus(202)
        ->json();

    expect($res['benchmark_id'])->toStartWith('pgbench_')
        ->and($res['run_ids'])->toHaveCount(2)
        // Sync queue driver: the member jobs finished inline.
        ->and($res['status'])->toBe('complete');

    $detail = $this->actingAs(benchUser())
        ->get('/playground/benchmark/'.$res['benchmark_id'])
        ->assertOk()
        ->json();

    expect($detail['comparison']['models'])->toHaveCount(2)
        ->and($detail['comparison']['status'])->toBe('complete')
        // claude-cheap: (100×1 + 100×5)/1M = 0.0006 vs pricey (100×10 + 1000×50)/1M = 0.051.
        ->and($detail['comparison']['verdicts']['cheapest']['model'])->toBe('claude-cheap')
        ->and($detail['comparison']['verdicts']['cheapest']['value'])->toBe(0.0006)
        ->and($detail['runs'])->toHaveCount(2)
        ->and(collect($detail['runs'])->pluck('output_text'))->toContain('Short answer.');
});

test('repeats produce member runs per model and medians in the comparison', function () {
    $cheap = benchModel('claude-cheap', 1, 5);
    $pricey = benchModel('claude-pricey', 10, 50);
    fakeBenchAgents();

    $res = $this->actingAs(benchUser())
        ->post('/playground/benchmark', [
            'capability' => 'text',
            'prompt' => 'Again',
            'model_ids' => [$cheap->id, $pricey->id],
            'repeats' => 2,
        ])
        ->assertStatus(202)
        ->json();

    expect($res['run_ids'])->toHaveCount(4);

    $detail = $this->actingAs(benchUser())
        ->get('/playground/benchmark/'.$res['benchmark_id'])
        ->json();

    $models = collect($detail['comparison']['models']);
    expect($models)->toHaveCount(2)
        ->and($models->firstWhere('model', 'claude-cheap')['runs_ok'])->toBe(2)
        ->and($models->firstWhere('model', 'claude-cheap')['metrics']['cost'])->toBe(0.0006);
});

test('the human verdict persists and a foreign run is rejected', function () {
    $cheap = benchModel('claude-cheap', 1, 5);
    $pricey = benchModel('claude-pricey', 10, 50);
    fakeBenchAgents();

    $user = benchUser();
    $res = $this->actingAs($user)
        ->post('/playground/benchmark', [
            'capability' => 'text',
            'prompt' => 'Pick one',
            'model_ids' => [$cheap->id, $pricey->id],
        ])
        ->json();

    $winnerRunId = $res['run_ids'][0];

    $this->actingAs($user)
        ->post('/playground/benchmark/'.$res['benchmark_id'].'/winner', [
            'run_id' => $winnerRunId,
            'note' => 'Cheaper and good enough for support.',
        ])
        ->assertOk();

    $detail = $this->actingAs($user)->get('/playground/benchmark/'.$res['benchmark_id'])->json();
    expect($detail['comparison']['winner']['run_id'])->toBe($winnerRunId)
        ->and($detail['comparison']['winner']['note'])->toBe('Cheaper and good enough for support.');

    // A run that belongs to no benchmark of ours must be rejected.
    $this->actingAs($user)
        ->post('/playground/benchmark/'.$res['benchmark_id'].'/winner', ['run_id' => 'pgrun_not_a_member'])
        ->assertStatus(422);
});

test('a benchmark needs at least two models', function () {
    $cheap = benchModel('claude-cheap', 1, 5);

    $this->actingAs(benchUser())
        ->postJson('/playground/benchmark', [
            'capability' => 'text',
            'prompt' => 'Solo',
            'model_ids' => [$cheap->id],
        ])
        ->assertStatus(422);
});

test('the benchmark history lists groups with models and winner', function () {
    $cheap = benchModel('claude-cheap', 1, 5);
    $pricey = benchModel('claude-pricey', 10, 50);
    fakeBenchAgents();

    $user = benchUser();
    $res = $this->actingAs($user)
        ->post('/playground/benchmark', [
            'capability' => 'text',
            'prompt' => 'A history-worthy comparison prompt',
            'model_ids' => [$cheap->id, $pricey->id],
        ])
        ->json();

    $benchmark = PlaygroundBenchmark::findOrFail($res['benchmark_id']);
    $winner = PlaygroundRun::query()->where('benchmark_id', $benchmark->id)->where('model', 'claude-cheap')->firstOrFail();
    $benchmark->forceFill(['winner_run_id' => $winner->id])->save();

    $list = $this->actingAs($user)->get('/playground/benchmarks')->assertOk()->json();

    expect($list['total'])->toBe(1)
        ->and($list['data'][0]['id'])->toBe($res['benchmark_id'])
        ->and($list['data'][0]['models'])->toContain('claude-cheap', 'claude-pricey')
        ->and($list['data'][0]['winner_model'])->toBe('claude-cheap')
        ->and($list['data'][0]['status'])->toBe('complete');
});
