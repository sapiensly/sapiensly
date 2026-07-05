<?php

use App\Ai\ExpressGateAgent;
use App\Jobs\ExpressDashboardJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Builder\BuilderCancellation;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Express\ExpressPipeline;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['express.enabled' => true]);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'xe_'.strtolower(Str::random(6)),
        'name' => 'Express',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'settings' => ['default_locale' => 'es-MX'],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $this->user);
});

it('starts an Express run: messages persisted, run created, job queued', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/express", [
            'conversation_id' => $this->conv->id,
            'prompt' => 'dashboard de tickets semanales',
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue()
        ->and($response['messages'])->toHaveCount(2);

    $run = PipelineRun::query()->find($response['run_id']);
    expect($run->prompt)->toBe('dashboard de tickets semanales')
        ->and($run->status)->toBe('running');

    Queue::assertPushed(ExpressDashboardJob::class, fn ($job) => $job->runId === $response['run_id']);
});

it('is hidden behind the flag', function () {
    config(['express.enabled' => false]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/express", [
            'conversation_id' => $this->conv->id, 'prompt' => 'x',
        ])
        ->assertNotFound();
});

it('runs the job end-to-end: progress narrated, report applied, run succeeded', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);

    $catalog = Mockery::mock(IntegrationCatalog::class);
    $catalog->shouldReceive('tools')->andReturn([
        ['name' => 'get-tickets-time-series-tool', 'description' => 'Weekly tickets', 'input_schema' => []],
    ]);
    app()->instance(IntegrationCatalog::class, $catalog);

    $mk = fn (string $x) => 'fld_'.strtolower((string) Str::ulid()).$x;
    $ids = ['d' => $mk('a'), 'c' => $mk('b'), 'n' => $mk('c')];
    $object = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'tickets_semanales',
        'name' => 'Tickets Semanales',
        'fields' => [
            ['id' => $ids['d'], 'slug' => 'semana', 'name' => 'Semana', 'type' => 'date'],
            ['id' => $ids['c'], 'slug' => 'categoria', 'name' => 'Categoría', 'type' => 'string'],
            ['id' => $ids['n'], 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => $integration->id, 'id_path' => 'id',
            'operations' => ['list' => ['mcp_tool' => 'get-tickets-time-series-tool', 'collection_path' => 'series']],
            'field_map' => [
                ['field_id' => $ids['d'], 'external_path' => 'semana'],
                ['field_id' => $ids['c'], 'external_path' => 'categoria'],
                ['field_id' => $ids['n'], 'external_path' => 'total'],
            ],
        ],
    ];
    $authoring = Mockery::mock(ConnectedObjectAuthoring::class);
    $authoring->shouldReceive('author')->once()->andReturn([
        'ok' => true, 'object' => $object,
        'rows' => [
            ['id' => 'W1', 'semana' => now()->utc()->subDays(3)->toDateString(), 'categoria' => 'Envíos', 'total' => 12],
            ['id' => 'W2', 'semana' => now()->utc()->subDays(10)->toDateString(), 'categoria' => 'Pagos', 'total' => 7],
        ],
        'clamped' => [], 'date_field_ids' => [$ids['d']], 'summary' => 'Creé «Tickets Semanales»',
    ]);
    app()->instance(ConnectedObjectAuthoring::class, $authoring);

    ExpressGateAgent::fake([
        ['tools' => ['get-tickets-time-series-tool'], 'substitutions' => [['asked' => 'CSAT', 'using' => 'SLA', 'reason' => 'no existe']], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => []],
        ['accept' => true, 'overrides' => []],
        ['title' => 'Panel de Tickets', 'purpose' => 'Volumen semanal.'],
        fn ($prompt) => [
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'Dato real.'])
                ->values()->all(),
        ],
    ]);

    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => '', 'status' => 'streaming',
    ]);
    $run = PipelineRun::create([
        'app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id,
        'prompt' => 'dashboard de tickets semanales',
    ]);

    (new ExpressDashboardJob($placeholder->id, $run->id, 'dashboard de tickets semanales'))
        ->handle(app(ExpressPipeline::class), app(BuilderCancellation::class));

    $run->refresh();
    $placeholder->refresh();

    expect($run->status)->toBe('succeeded')
        ->and($placeholder->status)->toBe('applied')
        ->and($placeholder->content)->toContain('Dashboard listo: Panel de Tickets')
        ->and($placeholder->content)->toContain('CSAT')          // honest substitution
        ->and($placeholder->applied_version_id)->not->toBeNull();

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    expect($manifest['pages'])->toHaveCount(1);
});

it('aborts before spending anything when Detener was pressed first', function () {
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => '', 'status' => 'streaming',
    ]);
    $run = PipelineRun::create([
        'app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id, 'prompt' => 'x',
    ]);
    app(BuilderCancellation::class)->request($this->conv);

    (new ExpressDashboardJob($placeholder->id, $run->id, 'x'))
        ->handle(app(ExpressPipeline::class), app(BuilderCancellation::class));

    expect($run->fresh()->status)->toBe('stopped')
        ->and($placeholder->fresh()->content)->toContain('detenido');
});
