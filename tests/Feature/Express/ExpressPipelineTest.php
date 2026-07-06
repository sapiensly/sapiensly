<?php

use App\Ai\ExpressGateAgent;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Builder\BuilderCancellation;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressHalt;
use App\Services\Express\ExpressPipeline;
use App\Services\Express\GateRunner;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
});

function xp_run($test): PipelineRun
{
    return PipelineRun::create([
        'app_id' => $test->testApp->id,
        'conversation_id' => $test->conv->id,
        'prompt' => 'dashboard de tickets',
    ]);
}

function xp_ctx($test): ExpressContext
{
    return new ExpressContext($test->testApp, $test->user, $test->conv, 'dashboard de tickets');
}

function xp_phase(string $name, Closure $body): ExpressPhase
{
    return new class($name, $body) implements ExpressPhase
    {
        public function __construct(private string $phaseName, private Closure $body) {}

        public function name(): string
        {
            return $this->phaseName;
        }

        public function announce(ExpressContext $context): string
        {
            return "▸ {$this->phaseName}";
        }

        public function run(ExpressContext $context, PipelineRun $run): void
        {
            ($this->body)($context, $run);
        }
    };
}

it('runs phases in order, persists transitions and finishes succeeded', function () {
    $run = xp_run($this);
    $ctx = xp_ctx($this);
    $order = [];
    $progress = [];
    $ctx->onProgress = function (string $line) use (&$progress) {
        $progress[] = $line;
    };

    $result = app(ExpressPipeline::class)->execute($run, $ctx, [
        xp_phase('one', function () use (&$order) {
            $order[] = 'one';
        }),
        xp_phase('two', function (ExpressContext $c) use (&$order) {
            $order[] = 'two';
            $c->page = ['slug' => 'dashboard', 'path' => '/dashboard'];
        }),
    ]);

    expect($order)->toBe(['one', 'two'])
        ->and($result->status)->toBe('succeeded')
        ->and($result->result['page']['slug'])->toBe('dashboard')
        ->and(collect($result->result['phases'])->pluck('phase')->all())->toBe(['one', 'two'])
        ->and($progress)->toBe(['▸ one', '▸ two'])
        ->and($result->finished_at)->not->toBeNull();
});

it('translates an ExpressHalt into its terminal status without failing', function () {
    $run = xp_run($this);

    $result = app(ExpressPipeline::class)->execute($run, xp_ctx($this), [
        xp_phase('fit', function () {
            throw new ExpressHalt('halted_unanswerable', 'Esta fuente no responde finanzas; puedo construir tickets o SLA.');
        }),
        xp_phase('never', fn () => throw new LogicException('must not run')),
    ]);

    expect($result->status)->toBe('halted_unanswerable')
        ->and($result->error)->toBeNull()
        ->and($result->result['notes'][0])->toContain('no responde finanzas');
});

it('marks unexpected phase exceptions as failed with the reason', function () {
    $run = xp_run($this);

    $result = app(ExpressPipeline::class)->execute($run, xp_ctx($this), [
        xp_phase('boom', fn () => throw new RuntimeException('integration unreachable')),
    ]);

    expect($result->status)->toBe('failed')
        ->and($result->error)->toContain('integration unreachable');
});

it('honors Detener between phases', function () {
    $run = xp_run($this);
    $cancellation = app(BuilderCancellation::class);

    $result = app(ExpressPipeline::class)->execute($run, xp_ctx($this), [
        xp_phase('one', fn () => $cancellation->request($this->conv)),
        xp_phase('never', fn () => throw new LogicException('must not run')),
    ]);

    expect($result->status)->toBe('stopped');
});

it('GateRunner returns the structured output and records telemetry', function () {
    ExpressGateAgent::fake([['tools' => ['weekly-tool'], 'core_unanswerable' => false]]);
    $run = xp_run($this);

    $result = app(GateRunner::class)->run(
        $run, 'fit_check', 'Eres el fit-check.', 'pedido + catálogo',
        fn ($schema) => ['tools' => $schema->array(), 'core_unanswerable' => $schema->boolean()],
        ['tools' => [], 'core_unanswerable' => false],
        $this->user,
    );

    expect($result['fallback_used'])->toBeFalse()
        ->and($result['output']['tools'])->toBe(['weekly-tool']);

    $gate = $run->fresh()->gates['fit_check'];
    expect($gate['fallback_used'])->toBeFalse()
        ->and($gate['latency_ms'])->toBeGreaterThanOrEqual(0);
});

it('GateRunner degrades to the default when the model errors twice', function () {
    ExpressGateAgent::fake([
        fn () => throw new RuntimeException('provider down'),
        fn () => throw new RuntimeException('provider still down'),
    ]);
    $run = xp_run($this);

    $result = app(GateRunner::class)->run(
        $run, 'voice', 'Eres la voz.', 'pedido',
        fn ($schema) => ['title' => $schema->string()],
        fn () => ['title' => 'Análisis de Tickets'],
        $this->user,
    );

    expect($result['fallback_used'])->toBeTrue()
        ->and($result['output']['title'])->toBe('Análisis de Tickets')
        ->and($run->fresh()->gates['voice']['fallback_used'])->toBeTrue();
});

it('GateRunner skips the retry when the first attempt timed out', function () {
    $calls = 0;
    ExpressGateAgent::fake([
        function () use (&$calls) {
            $calls++;
            throw new RuntimeException('cURL error 28: Operation timed out after 45002 milliseconds');
        },
        function () use (&$calls) {
            $calls++;
            throw new RuntimeException('should never be reached');
        },
    ]);
    $run = xp_run($this);

    $result = app(GateRunner::class)->run(
        $run, 'fit_check', 'x', 'y',
        fn ($schema) => ['a' => $schema->string()],
        ['a' => 'default'],
        $this->user,
    );

    expect($result['fallback_used'])->toBeTrue()
        ->and($result['output']['a'])->toBe('default')
        ->and($calls)->toBe(1); // no second 45s window burned
});
