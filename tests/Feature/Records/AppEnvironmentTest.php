<?php

use App\Http\Middleware\BindAppEnvironment;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\DemoDataGenerator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Support\Apps\EnvironmentContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The sandbox, and the wall between it and the books.
 *
 * One app, one manifest — two manifests drift the first time somebody edits a
 * button. What separates is the DATA and the SIDE EFFECTS. So what these pin
 * is the wall: a demo record must be invisible from production, a production
 * record invisible from the demo, and neither may be reached by forgetting to
 * ask.
 */
function envApp(User $owner): array
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'env_'.strtolower(Str::random(6)),
        'name' => 'Órdenes',
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_ordenes001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string']],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return [$app->fresh(), $manifest];
}

beforeEach(function () {
    app(EnvironmentContext::class)->set(EnvironmentContext::PRODUCTION);
});

it('starts in production, because the sandbox is never the fallback', function () {
    // Reading production where demo was meant is confusing. WRITING to
    // production while believing you are in a sandbox is not recoverable, so
    // the sandbox is only ever reached by asking for it.
    expect(app(EnvironmentContext::class)->current())->toBe('production');

    app(EnvironmentContext::class)->set('nonsense');
    expect(app(EnvironmentContext::class)->current())->toBe('production');
});

it('keeps each environment out of the other one entirely', function () {
    $owner = User::factory()->create();
    [$app, $manifest] = envApp($owner);
    $writes = app(RecordWriteService::class);
    $env = app(EnvironmentContext::class);

    $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'REAL-1'], $owner);
    $env->runIn(EnvironmentContext::DEMO, fn () => $writes->create(
        $app, $manifest, 'obj_ordenes001', ['folio' => 'FAKE-1'], $owner,
    ));

    $queries = app(RecordQueryService::class)->actingAs($owner);
    $folios = fn (): array => $queries
        ->query($app, ['object_id' => 'obj_ordenes001'], $manifest)
        ->pluck('data')
        ->pluck('folio')
        ->all();

    expect($folios())->toBe(['REAL-1']);
    expect($env->runIn(EnvironmentContext::DEMO, $folios))->toBe(['FAKE-1']);

    // Both rows are really there; only the wall is doing the work.
    expect(Record::where('app_id', $app->id)->count())->toBe(2);
});

it('counts and aggregates behind the same wall', function () {
    // The filter lives in the ONE method every read passes through, so a
    // count, an aggregate and a find are covered without each remembering.
    $owner = User::factory()->create();
    [$app, $manifest] = envApp($owner);
    $writes = app(RecordWriteService::class);
    $env = app(EnvironmentContext::class);

    $real = $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'REAL-1'], $owner);
    $env->runIn(EnvironmentContext::DEMO, function () use ($writes, $app, $manifest, $owner) {
        $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'FAKE-1'], $owner);
        $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'FAKE-2'], $owner);
    });

    $queries = app(RecordQueryService::class)->actingAs($owner);

    expect($queries->count($app, ['object_id' => 'obj_ordenes001'], $manifest))->toBe(1)
        ->and($env->runIn(EnvironmentContext::DEMO, fn () => $queries->count($app, ['object_id' => 'obj_ordenes001'], $manifest)))->toBe(2);

    // A production record is not reachable BY ID from the sandbox either —
    // which is what stops a demo action from editing the real thing.
    expect($env->runIn(EnvironmentContext::DEMO, fn () => $queries->find($app, 'obj_ordenes001', $real->id, $manifest)))->toBeNull();
});

it('puts generated sample data in the sandbox, not in the books', function () {
    // It used to land in production, which was the only place there was. An
    // app whose real books are half invented is an app nobody can trust a
    // figure from.
    $owner = User::factory()->create();
    [$app, $manifest] = envApp($owner);

    app(DemoDataGenerator::class)->generate($app, $manifest, 3, null, $owner);

    $queries = app(RecordQueryService::class)->actingAs($owner);

    expect($queries->count($app, ['object_id' => 'obj_ordenes001'], $manifest))->toBe(0)
        ->and(app(EnvironmentContext::class)->runIn(
            EnvironmentContext::DEMO,
            fn () => $queries->count($app, ['object_id' => 'obj_ordenes001'], $manifest),
        ))->toBe(3);
});

it('puts the environment back when the work inside it throws', function () {
    // A leaked environment is the worst possible outcome: every later write in
    // the request would land on the wrong side, silently.
    $env = app(EnvironmentContext::class);

    try {
        $env->runIn(EnvironmentContext::DEMO, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect($env->current())->toBe('production');
});

it('lets a request put itself in the sandbox but never take itself out', function () {
    // The builder preview needs the first: it reads the demo on purpose, and a
    // surface that reads one environment and writes the other is worse than one
    // that gets both wrong. The asymmetry is the whole safety of it — putting
    // yourself in the sandbox costs nothing if you were lying about it; taking
    // yourself out is the direction where being wrong reaches real records.
    $middleware = app(BindAppEnvironment::class);
    $env = app(EnvironmentContext::class);

    $run = function (array $body) use ($middleware, $env): string {
        $request = Request::create('/r/demo_app/actions', 'POST', $body);
        $request->setLaravelSession(app('session.store'));
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name): string
            {
                return $name === 'app_slug' ? 'demo_app' : '';
            }
        });

        $middleware->handle($request, fn () => new Response);

        return $env->current();
    };

    // In: honoured.
    expect($run(['environment' => 'demo']))->toBe('demo');

    // Out: ignored. The session is what says you may be in production, and a
    // request body is not the session.
    app('session.store')->put(BindAppEnvironment::SESSION_PREFIX.'demo_app', 'demo');
    expect($run(['environment' => 'production']))->toBe('demo');
});
