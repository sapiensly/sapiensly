<?php

use App\Exceptions\TenantStorageNotConfiguredException;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudProviderService;
use App\Services\Storage\TenantStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->storage = app(TenantStorage::class);
});

/** Make the env-based global S3 disk look configured. */
function fakeGlobalS3(): void
{
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'env-key',
        'secret' => 'env-secret',
        'bucket' => 'env-bucket',
        'region' => 'us-east-1',
    ]);
}

test('diskNameForOwner uses a personal storage provider over the global env disk', function () {
    fakeGlobalS3();
    $user = User::factory()->create();
    $provider = CloudProvider::factory()->storage()->create(['user_id' => $user->id]);

    $name = $this->storage->diskNameForOwner(null, $user->id);

    expect($name)->toBe(CloudProviderService::PROVIDER_DISK_PREFIX.$provider->id)
        ->and(config("filesystems.disks.{$name}.driver"))->toBe('s3');
});

test('diskNameForOwner falls back to the global env disk when no provider exists', function () {
    fakeGlobalS3();
    $user = User::factory()->create();

    expect($this->storage->diskNameForOwner(null, $user->id))->toBe('s3');
});

test('diskNameForOwner refuses when nothing is configured', function () {
    config()->set('filesystems.disks.s3', null);
    $user = User::factory()->create();

    $this->storage->diskNameForOwner(null, $user->id);
})->throws(TenantStorageNotConfiguredException::class);

test('an org tenant provider wins over a personal one for the org owner', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-ts']);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $tenant = CloudProvider::factory()->storage()->forOrganization($org, $user)->create();

    $name = $this->storage->diskNameForOwner($org->id, $user->id);

    expect($name)->toBe(CloudProviderService::PROVIDER_DISK_PREFIX.$tenant->id);
});

test('a file written to a personal provider disk round-trips on the serve path', function () {
    $user = User::factory()->create();
    $provider = CloudProvider::factory()->storage()->create(['user_id' => $user->id]);

    // Resolve + register the owner disk, then fake it so byte I/O stays local.
    $name = $this->storage->diskNameForOwner(null, $user->id);
    Storage::fake($name);

    $this->storage->diskFromName($name)->put('uploads/file.txt', 'hello');

    // Simulate a separate process: drop the registered config, then serve by
    // the persisted name. diskFromName must re-register and read it back.
    config()->set("filesystems.disks.{$name}", null);
    $disk = $this->storage->diskFromName($name);

    expect($disk->get('uploads/file.txt'))->toBe('hello');
});
