<?php

use App\Ai\ExpressGateAgent;
use App\Jobs\ExpressDashboardJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Apps\AppNamer;
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
    $catalog->shouldReceive('knownShapes')->andReturn([]);
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
            ['id' => 'W3', 'semana' => now()->utc()->subDays(17)->toDateString(), 'categoria' => 'Envíos', 'total' => 9],
        ],
        'clamped' => [], 'date_field_ids' => [$ids['d']], 'summary' => 'Creé «Tickets Semanales»',
    ]);
    app()->instance(ConnectedObjectAuthoring::class, $authoring);

    ExpressGateAgent::fake([
        ['tools' => ['get-tickets-time-series-tool'], 'substitutions' => [['asked' => 'CSAT', 'using' => 'SLA', 'reason' => 'no existe']], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => []],
        ['accept' => true, 'overrides' => []],
        fn ($prompt) => [
            'title' => 'Panel de Tickets',
            'purpose' => 'Volumen semanal.',
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'Dato real.'])
                ->values()->all(),
        ],
        ['fixes' => []], // the sync-queued G-3 verifier finds nothing to fix
    ]);

    $this->testApp->update(['description' => null]); // unnamed app has no description yet

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

    // The progress narration survives as its own message; the report is NEW.
    expect($run->status)->toBe('succeeded')
        ->and($placeholder->status)->toBe('none')
        ->and($placeholder->content)->toContain('Compilando');

    $report = BuilderMessage::query()
        ->where('conversation_id', $this->conv->id)
        ->orderByDesc('created_at')->orderByDesc('id')
        ->first();
    expect($report->id)->not->toBe($placeholder->id)
        ->and($report->status)->toBe('applied')
        ->and($report->content)->toContain('Panel de Tickets')
        ->and($report->content)->toContain('CSAT')          // honest substitution
        ->and($report->content)->not->toContain('Compuertas') // never expose gate/model internals
        ->and($report->content)->not->toContain('fit_check')
        ->and($report->applied_version_id)->not->toBeNull();

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    expect($manifest['pages'])->toHaveCount(1);

    // The app's description is filled from the FINISHED dashboard's purpose
    // (the voice gate), not the raw prompt — and synced onto the manifest.
    $this->testApp->refresh();
    expect($this->testApp->description)->toBe('Volumen semanal.')
        ->and($manifest['description'] ?? null)->toBe('Volumen semanal.');
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

it('surfaces an error when a hard-killed run left the placeholder a silent «none»', function () {
    // Prod app_…59t9: the worker died in acquire → the placeholder was left at
    // `none` with only progress narration and NO report. failed() must still
    // append a user-facing error (the streaming/pending guard alone let it
    // vanish until the 10-min reaper).
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant',
        'content' => "Localizando la fuente de datos…\nComparando tu pedido…",
        'status' => 'none', // a partial finalization already moved it off streaming
    ]);
    $run = PipelineRun::create([
        'app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id, 'prompt' => 'x', 'status' => 'running',
    ]);

    // The exact prod exception — its message leaks the job class name and
    // "attempted too many times". The user must NEVER see either.
    (new ExpressDashboardJob($placeholder->id, $run->id, 'x'))
        ->failed(new RuntimeException('App\Jobs\ExpressDashboardJob has been attempted too many times.'));

    // Narration kept; a NEW error message explains the interruption cleanly.
    expect($placeholder->fresh()->content)->toContain('Localizando');
    $error = BuilderMessage::where('conversation_id', $this->conv->id)
        ->where('id', '>', $placeholder->id)->first();
    expect($error)->not->toBeNull()
        ->and($error->status)->toBe('error')
        ->and($error->content)->toContain('se interrumpió')
        ->and($error->content)->toContain('quedó guardado')
        ->and($error->content)->not->toContain('attempted too many times')
        ->and($error->content)->not->toContain('ExpressDashboardJob');
});

it('does NOT double-report when a failure report already followed the placeholder', function () {
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => 'progreso…', 'status' => 'none',
    ]);
    BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => 'No pude terminar el dashboard.', 'status' => 'error',
    ]);
    $run = PipelineRun::create([
        'app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id, 'prompt' => 'x', 'status' => 'failed',
    ]);

    (new ExpressDashboardJob($placeholder->id, $run->id, 'x'))->failed(new RuntimeException('late kill'));

    // Still exactly the two messages — the existing report stands, no duplicate.
    expect(BuilderMessage::where('conversation_id', $this->conv->id)->count())->toBe(2);
});

it('writes the description with the short-summary model when the voice gate defaulted', function () {
    // Same build, but the voice gate returns NO purpose (slow-model default).
    // The description must be written by the short-summary model over the built
    // dashboard — never the raw prompt (the user's complaint).
    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1', 'is_mcp' => true, 'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'], 'status' => 'active',
    ]);
    $catalog = Mockery::mock(IntegrationCatalog::class);
    $catalog->shouldReceive('knownShapes')->andReturn([]);
    $catalog->shouldReceive('tools')->andReturn([['name' => 'get-tickets-time-series-tool', 'description' => 'Weekly tickets', 'input_schema' => []]]);
    app()->instance(IntegrationCatalog::class, $catalog);

    $mk = fn (string $x) => 'fld_'.strtolower((string) Str::ulid()).$x;
    $ids = ['d' => $mk('a'), 'c' => $mk('b'), 'n' => $mk('c')];
    $object = [
        'id' => 'obj_'.strtolower((string) Str::ulid()), 'slug' => 'tickets_semanales', 'name' => 'Tickets Semanales',
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
            ['id' => 'W3', 'semana' => now()->utc()->subDays(17)->toDateString(), 'categoria' => 'Envíos', 'total' => 9],
        ],
        'clamped' => [], 'date_field_ids' => [$ids['d']], 'summary' => 'Creé «Tickets Semanales»',
    ]);
    app()->instance(ConnectedObjectAuthoring::class, $authoring);

    ExpressGateAgent::fake([
        ['tools' => ['get-tickets-time-series-tool'], 'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => []],
        ['accept' => true, 'overrides' => []],
        ['title' => 'Panel de Tickets', 'purpose' => '', 'insights' => []], // voice DEFAULTED — no purpose
        ['fixes' => []],
    ]);

    // The short-summary model writes the description from the built dashboard.
    $namer = Mockery::mock(AppNamer::class);
    $namer->shouldReceive('describeDashboard')->once()->andReturn('Evolución semanal del volumen de tickets por categoría.');
    app()->instance(AppNamer::class, $namer);

    $this->testApp->update(['description' => null]);
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => '', 'status' => 'streaming',
    ]);
    $run = PipelineRun::create([
        'app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id, 'prompt' => 'crea un dashboard de tickets de yuhu',
    ]);

    (new ExpressDashboardJob($placeholder->id, $run->id, 'crea un dashboard de tickets de yuhu'))
        ->handle(app(ExpressPipeline::class), app(BuilderCancellation::class));

    $this->testApp->refresh();
    expect($this->testApp->description)->toBe('Evolución semanal del volumen de tickets por categoría.')
        ->and($this->testApp->description)->not->toContain('crea un dashboard'); // never the raw prompt
});
