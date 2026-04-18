<?php

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('sysadmin');
});

test('non-admin cannot access the global cloud page', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/admin/system/global-cloud')->assertForbidden();
});

test('admin can view the global cloud page with empty configuration', function () {
    actingAs($this->admin)
        ->get('/admin/system/global-cloud')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/GlobalCloud')
                ->where('existing.storage', null)
                ->where('existing.database', null)
                ->has('drivers.storage', 4)
                ->has('drivers.database', 1),
        );
});

test('admin can save a global S3 storage provider', function () {
    actingAs($this->admin)->post('/admin/system/global-cloud/storage', [
        'driver' => 's3',
        'credentials' => [
            'bucket' => 'platform-global',
            'region' => 'us-east-1',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'super-secret-value',
        ],
    ])->assertRedirect('/admin/system/global-cloud');

    $globals = CloudProvider::where('visibility', Visibility::Global)
        ->where('kind', 'storage')
        ->get();

    expect($globals)->toHaveCount(1);

    $provider = $globals->first();
    expect($provider->driver)->toBe('s3')
        ->and($provider->display_name)->toBe('Amazon S3')
        ->and($provider->credentials['bucket'])->toBe('platform-global')
        ->and($provider->credentials['secret'])->toBe('super-secret-value')
        ->and($provider->user_id)->toBeNull()
        ->and($provider->organization_id)->toBeNull();
});

test('admin can save a global PostgreSQL database provider', function () {
    actingAs($this->admin)->post('/admin/system/global-cloud/database', [
        'driver' => 'postgresql',
        'credentials' => [
            'host' => 'db.internal',
            'port' => '5432',
            'database' => 'sapiensly_shared',
            'username' => 'tenant_rw',
            'password' => 'shhh',
            'sslmode' => 'require',
        ],
    ])->assertRedirect('/admin/system/global-cloud');

    $globals = CloudProvider::where('visibility', Visibility::Global)
        ->where('kind', 'database')
        ->get();

    expect($globals)->toHaveCount(1);

    $provider = $globals->first();
    expect($provider->driver)->toBe('postgresql')
        ->and($provider->credentials['host'])->toBe('db.internal')
        ->and($provider->credentials['password'])->toBe('shhh');
});

test('resubmitting storage replaces the existing global row instead of duplicating', function () {
    actingAs($this->admin)->post('/admin/system/global-cloud/storage', [
        'driver' => 's3',
        'credentials' => [
            'bucket' => 'old',
            'region' => 'us-east-1',
            'key' => 'k-old',
            'secret' => 's-old',
        ],
    ])->assertRedirect('/admin/system/global-cloud');

    actingAs($this->admin)->post('/admin/system/global-cloud/storage', [
        'driver' => 'r2',
        'credentials' => [
            'bucket' => 'new',
            'region' => 'auto',
            'key' => 'k-new',
            'secret' => 's-new',
            'endpoint' => 'https://r2.example.com',
        ],
    ])->assertRedirect('/admin/system/global-cloud');

    $providers = CloudProvider::where('kind', 'storage')->get();
    expect($providers)->toHaveCount(1);
    expect($providers->first()->driver)->toBe('r2');
    expect($providers->first()->credentials['bucket'])->toBe('new');
});

test('non-admin cannot save storage or database providers', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/admin/system/global-cloud/storage', [
        'driver' => 's3',
        'credentials' => [
            'bucket' => 'b', 'region' => 'r', 'key' => 'k', 'secret' => 's',
        ],
    ])->assertForbidden();

    actingAs($user)->post('/admin/system/global-cloud/database', [
        'driver' => 'postgresql',
        'credentials' => [
            'host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'p',
        ],
    ])->assertForbidden();

    expect(CloudProvider::count())->toBe(0);
});

test('storage save rejects an unsupported driver', function () {
    actingAs($this->admin)->post('/admin/system/global-cloud/storage', [
        'driver' => 'postgresql',
        'credentials' => ['bucket' => 'x'],
    ])->assertSessionHasErrors('driver');
});

test('storage save rejects missing required credential fields', function () {
    actingAs($this->admin)->post('/admin/system/global-cloud/storage', [
        'driver' => 's3',
        'credentials' => ['bucket' => 'only-bucket'],
    ])->assertSessionHasErrors([
        'credentials.region',
        'credentials.key',
        'credentials.secret',
    ]);
});

test('database save rejects missing required credential fields', function () {
    actingAs($this->admin)->post('/admin/system/global-cloud/database', [
        'driver' => 'postgresql',
        'credentials' => ['host' => 'only-host'],
    ])->assertSessionHasErrors([
        'credentials.database',
        'credentials.username',
        'credentials.password',
    ]);
});

test('test-storage with use_saved flag returns error when no provider exists', function () {
    actingAs($this->admin)
        ->postJson('/admin/system/global-cloud/storage/test-connection', ['use_saved' => true])
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('test-database with use_saved flag returns error when no provider exists', function () {
    actingAs($this->admin)
        ->postJson('/admin/system/global-cloud/database/test-connection', ['use_saved' => true])
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('non-admin cannot test connections', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/admin/system/global-cloud/storage/test-connection', ['use_saved' => true])
        ->assertForbidden();
});

test('index presents masked credentials for an existing storage provider', function () {
    CloudProvider::factory()->storage()->global()->create([
        'credentials' => [
            'bucket' => 'shown-bucket',
            'region' => 'us-west-2',
            'key' => 'AKIAABCDEFGHIJKLMNOP',
            'secret' => 'very-secret-value-xxx',
        ],
    ]);

    actingAs($this->admin)
        ->get('/admin/system/global-cloud')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('existing.storage.driver', 's3')
            ->where('existing.storage.masked_credentials.bucket', 'shown-bucket')
            ->where('existing.storage.masked_credentials.region', 'us-west-2')
            ->where('existing.storage.masked_credentials.secret', fn ($v) => str_contains((string) $v, '...'))
            ->where('existing.storage.masked_credentials.key', fn ($v) => str_contains((string) $v, '...'))
        );
});
