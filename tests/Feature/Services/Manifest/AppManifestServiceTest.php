<?php

use App\Models\App;
use App\Models\AppVersion;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

function manifest(string $name = 'X'): array
{
    $appId = 'app_'.strtolower((string) Illuminate\Support\Str::ulid());
    $rolId = 'rol_'.strtolower((string) Illuminate\Support\Str::ulid());

    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => $name,
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => $rolId, 'slug' => 'admin', 'name' => 'Admin'],
            ],
        ],
    ];
}

function makeService(): AppManifestService
{
    return new AppManifestService(
        new ManifestValidator,
        app(CacheRepository::class),
    );
}

it('creates a new version and points current_version_id at it', function () {
    $app = App::factory()->create();

    $version = makeService()->createVersion($app, manifest('First'), null, 'initial');

    $app->refresh();

    expect($version->version_number)->toBe(1)
        ->and($app->current_version_id)->toBe($version->id)
        ->and($version->manifest['name'])->toBe('First')
        ->and($version->manifest['version'])->toBe(1);
});

it('increments version_number sequentially across createVersion calls', function () {
    $app = App::factory()->create();
    $service = makeService();

    $v1 = $service->createVersion($app, manifest('A'));
    $v2 = $service->createVersion($app, manifest('B'));
    $v3 = $service->createVersion($app, manifest('C'));

    expect([$v1->version_number, $v2->version_number, $v3->version_number])->toBe([1, 2, 3]);

    $app->refresh();
    expect($app->current_version_id)->toBe($v3->id);
});

it('rejects an invalid manifest with InvalidManifestException', function () {
    $app = App::factory()->create();
    $bad = manifest();
    unset($bad['permissions']);

    expect(fn () => makeService()->createVersion($app, $bad))
        ->toThrow(InvalidManifestException::class);

    expect($app->refresh()->current_version_id)->toBeNull();
});

it('returns the active manifest via getActiveManifest', function () {
    $app = App::factory()->create();
    $service = makeService();
    $service->createVersion($app, manifest('Hello'));
    $service->createVersion($app, manifest('World'));

    $manifest = $service->getActiveManifest($app->refresh());

    expect($manifest['name'])->toBe('World');
});

it('returns null from getActiveManifest when app has no versions', function () {
    $app = App::factory()->create();

    expect(makeService()->getActiveManifest($app))->toBeNull();
});

it('applies an RFC 6902 patch as a new version', function () {
    $app = App::factory()->create();
    $service = makeService();
    $service->createVersion($app, manifest('Before'));

    $patch = [
        ['op' => 'replace', 'path' => '/name', 'value' => 'After'],
    ];
    $version = $service->applyPatch($app->refresh(), $patch, null, 'rename');

    expect($version->manifest['name'])->toBe('After')
        ->and($version->version_number)->toBe(2)
        ->and($version->change_summary)->toBe('rename');
});

it('rolls back by creating a new version with prior manifest content', function () {
    $app = App::factory()->create();
    $service = makeService();
    $v1 = $service->createVersion($app, manifest('Original'));
    $service->createVersion($app, manifest('Changed'));

    $v3 = $service->rollbackTo($app->refresh(), $v1);

    expect($v3->version_number)->toBe(3)
        ->and($v3->manifest['name'])->toBe('Original')
        ->and(str_contains($v3->change_summary, 'Rollback to v1'))->toBeTrue();

    expect($app->refresh()->current_version_id)->toBe($v3->id);
});

it('caches getActiveManifest result', function () {
    Cache::flush();

    $app = App::factory()->create();
    $service = makeService();
    $version = $service->createVersion($app, manifest('Cached'));

    $service->getActiveManifest($app->refresh());

    // Mutate the DB row out-of-band; cached value should still be returned.
    AppVersion::query()->where('id', $version->id)->update([
        'manifest' => array_merge($version->manifest, ['name' => 'Mutated']),
    ]);

    expect($service->getActiveManifest($app->fresh())['name'])->toBe('Cached');
});

it('rollbackTo rejects versions from other apps', function () {
    $appA = App::factory()->create();
    $appB = App::factory()->create();
    $service = makeService();
    $vA = $service->createVersion($appA, manifest('A'));

    expect(fn () => $service->rollbackTo($appB, $vA))
        ->toThrow(InvalidArgumentException::class);
});

it('applyPatch can append the first workflow with /workflows/- when the key is absent', function () {
    $app = App::factory()->create();
    $service = makeService();
    $service->createVersion($app, manifest('No workflows yet')); // manifest() omits the workflows key

    $stp = 'stp_'.strtolower((string) Illuminate\Support\Str::ulid());
    $wkf = 'wkf_'.strtolower((string) Illuminate\Support\Str::ulid());
    $workflow = [
        'id' => $wkf, 'slug' => 'notify', 'name' => 'Notify',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => $stp, 'type' => 'log', 'message' => 'hi']],
    ];

    $version = $service->applyPatch($app->refresh(), [
        ['op' => 'add', 'path' => '/workflows/-', 'value' => $workflow],
    ], null, 'add first workflow');

    expect($version->manifest['workflows'])->toHaveCount(1)
        ->and($version->manifest['workflows'][0]['id'])->toBe($wkf);
});
