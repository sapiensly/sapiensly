<?php

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudProviderService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CloudProviderService::class);
});

function makeOrganization(string $slug = 'acme'): Organization
{
    return Organization::create([
        'name' => 'Acme '.$slug,
        'slug' => $slug,
    ]);
}

test('resolveStorage falls through to global when tenant has none', function () {
    $org = makeOrganization('no-tenant-storage');
    $global = CloudProvider::factory()->storage()->global()->create();

    $resolved = $this->service->resolveStorage($org);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($global->id)
        ->and($resolved->visibility)->toBe(Visibility::Global);
});

test('resolveStorage prefers tenant provider over global', function () {
    $org = makeOrganization('has-tenant-storage');
    CloudProvider::factory()->storage()->global()->create();
    $tenant = CloudProvider::factory()->storage()->forOrganization($org)->create();

    $resolved = $this->service->resolveStorage($org);

    expect($resolved->id)->toBe($tenant->id)
        ->and($resolved->visibility)->toBe(Visibility::Organization);
});

test('resolveStorage returns null when neither tenant nor global is configured', function () {
    $org = makeOrganization('empty');

    expect($this->service->resolveStorage($org))->toBeNull();
});

test('resolveDatabase uses the same tenant then global order', function () {
    $org = makeOrganization('db-org');
    $global = CloudProvider::factory()->postgres()->global()->create();

    expect($this->service->resolveDatabase($org)->id)->toBe($global->id);

    $tenant = CloudProvider::factory()->postgres()->forOrganization($org)->create();

    expect($this->service->resolveDatabase($org)->id)->toBe($tenant->id);
});

test('resolveStorage with null organization only returns globals', function () {
    $global = CloudProvider::factory()->storage()->global()->create();

    expect($this->service->resolveStorage(null)->id)->toBe($global->id);
});

test('resolve ignores inactive providers', function () {
    $org = makeOrganization('inactive');
    CloudProvider::factory()->storage()->forOrganization($org)->create(['status' => 'inactive']);
    $global = CloudProvider::factory()->storage()->global()->create();

    expect($this->service->resolveStorage($org)->id)->toBe($global->id);
});

test('resolve ignores providers of a different kind', function () {
    $org = makeOrganization('mixed');
    CloudProvider::factory()->postgres()->forOrganization($org)->create();

    expect($this->service->resolveStorage($org))->toBeNull();
});

test('upsertGlobalProvider creates a single global row per kind', function () {
    $first = $this->service->upsertGlobalProvider('storage', 's3', [
        'bucket' => 'a', 'region' => 'us-east-1', 'key' => 'k1', 'secret' => 'x',
    ]);

    expect(CloudProvider::where('visibility', Visibility::Global)->where('kind', 'storage')->count())->toBe(1);

    $second = $this->service->upsertGlobalProvider('storage', 'r2', [
        'bucket' => 'b', 'region' => 'auto', 'key' => 'k2', 'secret' => 'y', 'endpoint' => 'https://r2.example',
    ]);

    expect($second->id)->toBe($first->id)
        ->and($second->driver)->toBe('r2')
        ->and($second->credentials['bucket'])->toBe('b')
        ->and(CloudProvider::where('visibility', Visibility::Global)->where('kind', 'storage')->count())->toBe(1);
});

test('upsertGlobalProvider rejects a driver not supported for the kind', function () {
    $this->service->upsertGlobalProvider('storage', 'postgresql', []);
})->throws(InvalidArgumentException::class);

test('upsertTenantProvider scopes to organization and does not leak globally', function () {
    $org = makeOrganization('scoped');
    $user = User::factory()->create(['organization_id' => $org->id]);

    $tenant = $this->service->upsertTenantProvider($org, 'database', 'postgresql', [
        'host' => 'db', 'port' => '5432', 'database' => 't', 'username' => 'u', 'password' => 'p',
    ], $user->id);

    expect($tenant->organization_id)->toBe($org->id)
        ->and($tenant->visibility)->toBe(Visibility::Organization)
        ->and(CloudProvider::where('visibility', Visibility::Global)->count())->toBe(0);
});

test('maskCredentials masks sensitive fields and passes through others', function () {
    $masked = $this->service->maskCredentials([
        'bucket' => 'public-bucket',
        'region' => 'us-east-1',
        'key' => 'AKIAABCDEFGHIJKLMNOP',
        'secret' => 'verysecretvalue12345678',
        'password' => 'hunter2xx',
    ]);

    expect($masked['bucket'])->toBe('public-bucket')
        ->and($masked['region'])->toBe('us-east-1')
        ->and($masked['key'])->toBe('AKIA...MNOP')
        ->and($masked['secret'])->not->toBe('verysecretvalue12345678')
        ->and($masked['password'])->not->toBe('hunter2xx')
        ->and(str_contains($masked['secret'], '...'))->toBeTrue();
});

test('credentials are stored encrypted at rest', function () {
    $provider = CloudProvider::factory()->storage()->global()->create([
        'credentials' => ['bucket' => 'b', 'region' => 'r', 'key' => 'k', 'secret' => 'supersecret'],
    ]);

    $raw = DB::table('cloud_providers')->where('id', $provider->id)->value('credentials');

    expect($raw)->not->toContain('supersecret')
        ->and($provider->fresh()->credentials['secret'])->toBe('supersecret');
});

test('getDriverOptions returns only drivers for the requested kind', function () {
    $storageDrivers = collect($this->service->getDriverOptions('storage'))->pluck('value');
    $dbDrivers = collect($this->service->getDriverOptions('database'))->pluck('value');

    expect($storageDrivers->all())->toEqualCanonicalizing(['s3', 'r2', 'minio', 'digitalocean_spaces'])
        ->and($dbDrivers->all())->toEqual(['postgresql']);
});

test('diskForOrganizationOrFallback falls back to the documents disk when nothing is configured', function () {
    config(['filesystems.disks.documents' => [
        'driver' => 'local',
        'root' => storage_path('app/test-documents-'.uniqid()),
        'throw' => true,
    ]]);

    $org = makeOrganization('fallback-org');

    $disk = $this->service->diskForOrganizationOrFallback($org->id);

    expect($disk)->not->toBeNull();
    $disk->put('probe.txt', 'hello');
    expect($disk->get('probe.txt'))->toBe('hello');
});

test('diskForOrganizationOrFallback returns a built disk when a global storage provider exists', function () {
    CloudProvider::factory()->storage()->global()->create([
        'credentials' => [
            'bucket' => 'global-bucket',
            'region' => 'us-east-1',
            'key' => 'AKIA',
            'secret' => 'secret',
        ],
    ]);

    $disk = $this->service->diskForOrganizationOrFallback(null);

    expect($disk)->not->toBeNull()
        ->and($disk)->toBeInstanceOf(Filesystem::class);
});

test('diskForOrganizationOrFallback handles a null organization id', function () {
    config(['filesystems.disks.documents' => [
        'driver' => 'local',
        'root' => storage_path('app/test-documents-'.uniqid()),
        'throw' => true,
    ]]);

    $disk = $this->service->diskForOrganizationOrFallback(null);

    expect($disk)->not->toBeNull();
});

test('tenantConnectionFor returns the default connection when no provider is configured', function () {
    $org = makeOrganization('no-db-provider');
    $user = User::factory()->create(['organization_id' => $org->id]);

    $connection = $this->service->tenantConnectionFor($user);

    expect($connection->getName())->toBe(config('database.default'));
});

test('tenantConnectionFor with null user returns the default connection', function () {
    $connection = $this->service->tenantConnectionFor(null);

    expect($connection->getName())->toBe(config('database.default'));
});

test('tenantConnectionFor returns the tenant_custom connection when a provider is resolved', function () {
    CloudProvider::factory()->postgres()->global()->create([
        'credentials' => [
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'probe_'.uniqid(),
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);

    $user = User::factory()->create(['organization_id' => null]);

    $connection = $this->service->tenantConnectionFor($user);

    expect($connection->getName())->toBe(CloudProviderService::RUNTIME_DB_CONNECTION)
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.driver'))
        ->toBe('pgsql');
});

test('tenantConnectionFor prefers the tenant provider over the global one', function () {
    $org = makeOrganization('has-tenant-db');
    $user = User::factory()->create(['organization_id' => $org->id]);

    CloudProvider::factory()->postgres()->global()->create([
        'credentials' => [
            'host' => 'global-host',
            'port' => '5432',
            'database' => 'global_db',
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);
    CloudProvider::factory()->postgres()->forOrganization($org, $user)->create([
        'credentials' => [
            'host' => 'tenant-host',
            'port' => '5432',
            'database' => 'tenant_db',
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);

    $this->service->tenantConnectionFor($user);

    expect(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.host'))->toBe('tenant-host')
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.database'))->toBe('tenant_db');
});

test('buildConnection registers the runtime connection and returns it', function () {
    $provider = CloudProvider::factory()->postgres()->global()->create([
        'credentials' => [
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'nonexistent_'.uniqid(),
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);

    $connection = $this->service->buildConnection($provider);

    expect($connection)->not->toBeNull()
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.host'))->toBe('127.0.0.1')
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.database'))
        ->toStartWith('nonexistent_');
});
