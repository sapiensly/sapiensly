<?php

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudProviderService;
use App\Services\Security\Ssrf\SsrfBlockedException;
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

test('resolveDatabaseFor uses a personal user provider in a personal (no-org) context', function () {
    $user = User::factory()->create(); // personal — no organization
    $personal = CloudProvider::factory()->postgres()->create(['user_id' => $user->id]);

    $resolved = $this->service->resolveDatabaseFor($user->organization, $user);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($personal->id)
        ->and($resolved->visibility)->toBe(Visibility::Private);
});

test('resolveDatabaseFor prefers a personal provider over a global one', function () {
    $user = User::factory()->create();
    CloudProvider::factory()->postgres()->global()->create();
    $personal = CloudProvider::factory()->postgres()->create(['user_id' => $user->id]);

    expect($this->service->resolveDatabaseFor($user->organization, $user)->id)->toBe($personal->id);
});

test('resolveDatabaseFor falls back to global when a personal user has no provider', function () {
    $user = User::factory()->create();
    $global = CloudProvider::factory()->postgres()->global()->create();

    expect($this->service->resolveDatabaseFor($user->organization, $user)->id)->toBe($global->id);
});

test('resolveDatabaseFor returns null when a personal user has no provider and no global exists', function () {
    $user = User::factory()->create();

    expect($this->service->resolveDatabaseFor($user->organization, $user))->toBeNull();
});

test('resolveDatabaseFor still prefers the org tenant provider in an organization context', function () {
    $org = makeOrganization('owner-aware-org');
    $user = User::factory()->create(['organization_id' => $org->id]);
    CloudProvider::factory()->postgres()->global()->create();
    $tenant = CloudProvider::factory()->postgres()->forOrganization($org, $user)->create();

    expect($this->service->resolveDatabaseFor($user->organization, $user)->id)->toBe($tenant->id);
});

test('tenantConnectionFor builds the personal provider connection, not the app default', function () {
    $user = User::factory()->create();
    CloudProvider::factory()->postgres()->create(['user_id' => $user->id]);

    expect($this->service->tenantConnectionFor($user)->getName())
        ->toBe(CloudProviderService::RUNTIME_DB_CONNECTION);
});

test('tenantConnectionFor returns the app default when a personal user has no provider', function () {
    $user = User::factory()->create();

    expect($this->service->tenantConnectionFor($user)->getName())->toBe(config('database.default'));
});

test('buildConnection rejects an internal/private BYODB host (SSRF)', function (string $host) {
    $provider = CloudProvider::factory()->postgres()->create([
        'credentials' => [
            'host' => $host,
            'port' => '5432',
            'database' => 'evil',
            'username' => 'u',
            'password' => 'p',
        ],
    ]);

    expect(fn () => $this->service->buildConnection($provider))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'loopback' => '127.0.0.1',
    'private' => '10.1.2.3',
    'link-local / cloud metadata' => '169.254.169.254',
]);

test('testDatabaseForPayload refuses an internal host without probing it', function () {
    $result = $this->service->testDatabaseForPayload('postgresql', [
        'host' => '169.254.169.254',
        'database' => 'x',
        'username' => 'u',
        'password' => 'p',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('That database host is not allowed.')
        ->and($result)->not->toHaveKey('detail');
});

test('upsertTenantProvider rejects an internal storage endpoint (SSRF)', function (string $endpoint) {
    $org = makeOrganization('byos-ssrf-'.uniqid());
    $user = User::factory()->create(['organization_id' => $org->id]);

    expect(fn () => $this->service->upsertTenantProvider(
        $org,
        CloudProviderService::KIND_STORAGE,
        'minio',
        ['bucket' => 'b', 'region' => 'us', 'key' => 'k', 'secret' => 's', 'endpoint' => $endpoint],
        $user->id,
    ))->toThrow(SsrfBlockedException::class);
})->with([
    'loopback url' => 'http://127.0.0.1:9000',
    'private ip' => 'https://10.0.0.5:9000',
    'metadata host' => 'http://169.254.169.254',
    'bare internal host' => '192.168.1.10:9000',
]);

test('testStorageForPayload refuses an internal S3 endpoint without probing it', function () {
    $result = $this->service->testStorageForPayload('minio', [
        'bucket' => 'b',
        'endpoint' => 'http://169.254.169.254:9000',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('That storage endpoint is not allowed.')
        ->and($result)->not->toHaveKey('detail');
});

test('resolveStorage with null organization only returns globals', function () {
    $global = CloudProvider::factory()->storage()->global()->create();

    expect($this->service->resolveStorage(null)->id)->toBe($global->id);
});

test('resolveStorageFor uses a personal user storage provider in a personal context', function () {
    $user = User::factory()->create();
    $personal = CloudProvider::factory()->storage()->create(['user_id' => $user->id]);

    expect($this->service->resolveStorageFor($user->organization, $user)->id)->toBe($personal->id);
});

test('resolveStorageFor prefers a personal storage provider over a global one', function () {
    $user = User::factory()->create();
    CloudProvider::factory()->storage()->global()->create();
    $personal = CloudProvider::factory()->storage()->create(['user_id' => $user->id]);

    expect($this->service->resolveStorageFor($user->organization, $user)->id)->toBe($personal->id);
});

test('resolveStorageFor still prefers the org tenant provider in an organization context', function () {
    $org = makeOrganization('owner-aware-storage-org');
    $user = User::factory()->create(['organization_id' => $org->id]);
    CloudProvider::factory()->storage()->global()->create();
    $tenant = CloudProvider::factory()->storage()->forOrganization($org, $user)->create();

    expect($this->service->resolveStorageFor($user->organization, $user)->id)->toBe($tenant->id);
});

test('resolveStorageFor returns null when a personal user has no provider and no global exists', function () {
    $user = User::factory()->create();

    expect($this->service->resolveStorageFor($user->organization, $user))->toBeNull();
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
        'bucket' => 'b', 'region' => 'auto', 'key' => 'k2', 'secret' => 'y', 'endpoint' => 'https://1.1.1.1',
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
            'host' => '8.8.8.8', // public literal: passes the SSRF guard, never connected
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
            'host' => '1.1.1.1', // public literals: pass the SSRF guard, never connected
            'port' => '5432',
            'database' => 'global_db',
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);
    CloudProvider::factory()->postgres()->forOrganization($org, $user)->create([
        'credentials' => [
            'host' => '8.8.4.4',
            'port' => '5432',
            'database' => 'tenant_db',
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);

    $this->service->tenantConnectionFor($user);

    expect(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.host'))->toBe('8.8.4.4')
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.database'))->toBe('tenant_db');
});

test('buildConnection registers the runtime connection and returns it', function () {
    $provider = CloudProvider::factory()->postgres()->global()->create([
        'credentials' => [
            'host' => '8.8.8.8', // public literal: passes the SSRF guard, never connected
            'port' => '5432',
            'database' => 'nonexistent_'.uniqid(),
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);

    $connection = $this->service->buildConnection($provider);

    expect($connection)->not->toBeNull()
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.host'))->toBe('8.8.8.8')
        ->and(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.database'))
        ->toStartWith('nonexistent_');
});

test('diskDriverName embeds the provider id behind the prefix', function () {
    $provider = CloudProvider::factory()->storage()->global()->create();

    expect($this->service->diskDriverName($provider))
        ->toBe(CloudProviderService::PROVIDER_DISK_PREFIX.$provider->id);
});

test('registerDisk wires the provider credentials into the filesystem config', function () {
    $provider = CloudProvider::factory()->storage()->create([
        'credentials' => [
            'bucket' => 'owner-bucket',
            'region' => 'eu-west-1',
            'key' => 'AKIAOWNER',
            'secret' => 'shh',
        ],
    ]);

    $name = $this->service->registerDisk($provider);

    expect($name)->toBe(CloudProviderService::PROVIDER_DISK_PREFIX.$provider->id)
        ->and(config("filesystems.disks.{$name}.driver"))->toBe('s3')
        ->and(config("filesystems.disks.{$name}.bucket"))->toBe('owner-bucket')
        ->and(config("filesystems.disks.{$name}.region"))->toBe('eu-west-1');
});

test('ensureDiskRegistered re-registers a cloud_provider disk by name', function () {
    $provider = CloudProvider::factory()->storage()->create([
        'credentials' => ['bucket' => 'round-trip', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's'],
    ]);
    $name = $this->service->diskDriverName($provider);
    config()->set("filesystems.disks.{$name}", null);

    $returned = $this->service->ensureDiskRegistered($name);

    expect($returned)->toBe($name)
        ->and(config("filesystems.disks.{$name}.bucket"))->toBe('round-trip');
});

test('ensureDiskRegistered is a no-op for static disk names', function () {
    config()->set('filesystems.disks.documents', null);

    expect($this->service->ensureDiskRegistered('documents'))->toBe('documents')
        ->and(config('filesystems.disks.documents'))->toBeNull();
});
