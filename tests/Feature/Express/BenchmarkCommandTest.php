<?php

use App\Ai\ExpressGateAgent;
use App\Console\Commands\BenchmarkDashboards;
use App\Models\App;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;

it('runs a benchmark scenario end-to-end and writes the report', function () {
    Storage::fake('local');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = App::factory()->create(['user_id' => $user->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'bm_'.strtolower(Str::random(6)),
        'name' => 'Bench',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'settings' => ['default_locale' => 'es-MX'],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);
    $integration = Integration::factory()->forUser($user)->create([
        'base_url' => 'https://mcp.example.com/v1', 'is_mcp' => true,
        'auth_type' => 'bearer', 'auth_config' => ['token' => 'T'], 'status' => 'active',
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
        'slug' => 'tickets_semanales', 'name' => 'Tickets Semanales',
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
    $authoring->shouldReceive('previousWindowRowsMany')->andReturn([]);
    $authoring->shouldReceive('authorMany')->andReturn([[
        'ok' => true, 'object' => $object,
        'rows' => [['id' => 'W1', 'semana' => now()->utc()->subDays(3)->toDateString(), 'categoria' => 'Envíos', 'total' => 12]],
        'clamped' => [], 'date_field_ids' => [$ids['d']], 'summary' => 'obj',
    ]]);
    app()->instance(ConnectedObjectAuthoring::class, $authoring);

    ExpressGateAgent::fake([
        ['tools' => ['get-tickets-time-series-tool'], 'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => []],
        ['accept' => true, 'overrides' => []],
        fn ($prompt) => [
            'title' => 'Panel Bench',
            'purpose' => 'Prueba.',
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'Dato.'])
                ->values()->all(),
        ],
    ]);

    $this->artisan('benchmark:dashboards', [
        'app_slug' => $app->slug,
        '--scenario' => ['general'],
    ])->assertSuccessful();

    expect(PipelineRun::query()->where('kind', 'dashboard_express_benchmark')->count())->toBe(1)
        ->and(PipelineRun::query()->first()->status)->toBe('succeeded');

    $files = Storage::disk('local')->files('benchmarks');
    expect($files)->toHaveCount(1);
    $report = json_decode(Storage::disk('local')->get($files[0]), true);
    expect($report['general']['status'])->toBe('succeeded')
        ->and($report['general']['page']['path'])->not->toBeEmpty()
        // Q5: the deterministic quality audit ships with every scenario.
        ->and($report['general']['quality'])->toHaveKeys(['sanity_issues', 'blocks_audited', 'judge_score'])
        ->and($report['general']['quality']['judge_score'])->toBeNull(); // --judge off
});

it('derives the scenario prompts from the source domain, not from tickets', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = App::factory()->create(['user_id' => $user->id]);
    Integration::factory()->forUser($user)->create([
        'base_url' => 'https://mcp.example.com/v1', 'is_mcp' => true,
        'auth_type' => 'bearer', 'auth_config' => ['token' => 'T'], 'status' => 'active',
    ]);

    $catalog = Mockery::mock(IntegrationCatalog::class);
    $catalog->shouldReceive('knownShapes')->andReturn([]);
    $catalog->shouldReceive('tools')->andReturn([
        ['name' => 'get-nps-time-series-tool', 'description' => '', 'input_schema' => []],
        ['name' => 'get-nps-by-dimension-tool', 'description' => '', 'input_schema' => []],
        ['name' => 'get-nps-comments-tool', 'description' => '', 'input_schema' => []],
    ]);
    app()->instance(IntegrationCatalog::class, $catalog);

    $command = new ReflectionClass(BenchmarkDashboards::class);
    $instance = app(BenchmarkDashboards::class);
    $instance->setLaravel(app());
    // Bind an input so option('prompt') works.
    $instance->setInput(new ArrayInput(
        ['app_slug' => $app->slug],
        $instance->getDefinition(),
    ));
    $method = $command->getMethod('buildScenarios');
    $method->setAccessible(true);
    $scenarios = $method->invoke($instance, $user);

    expect($scenarios)->toHaveKeys(['general', 'tendencia', 'diagnostico', 'incontestable'])
        ->and($scenarios['general'])->toContain('nps')
        ->and($scenarios['general'])->not->toContain('tickets')
        // The halt scenario picked a topic with zero overlap vs nps tools.
        ->and($scenarios['incontestable'])->toContain('finanzas');
});
