<?php

use App\Models\App;
use App\Models\AppVersion;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use Database\Seeders\DemoAppSeeder;

it('creates a valid Mini CRM app with a manifest and 20 records', function () {
    $user = User::factory()->create();

    $this->seed(DemoAppSeeder::class);

    $appModel = App::query()->where('user_id', $user->id)->where('slug', 'mini_crm')->first();

    expect($appModel)->not->toBeNull()
        ->and($appModel->current_version_id)->not->toBeNull();

    $version = AppVersion::query()->where('app_id', $appModel->id)->first();
    expect($version)->not->toBeNull();

    // Manifest validates against the schema and cross-cutting rules.
    $result = (new ManifestValidator)->validate($version->manifest);
    expect($result->valid)->toBeTrue($result->valid ? '' : json_encode($result->errorsArray()));

    expect(Record::query()->where('app_id', $appModel->id)->count())->toBe(20);
});

it('is idempotent: running the seeder twice does not duplicate the app or records', function () {
    User::factory()->create();

    $this->seed(DemoAppSeeder::class);
    $this->seed(DemoAppSeeder::class);

    expect(App::query()->where('slug', 'mini_crm')->count())->toBe(1)
        ->and(Record::count())->toBe(20);
});

it('runs the apps:seed-demo command', function () {
    User::factory()->create();

    $this->artisan('apps:seed-demo')->assertSuccessful();

    expect(App::query()->where('slug', 'mini_crm')->exists())->toBeTrue();
});
