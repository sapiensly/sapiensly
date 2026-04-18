<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Middleware\ResolveTenantConnection;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\CloudProviderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function runMiddleware(?User $user): void
{
    $request = Request::create('/', 'GET');
    if ($user) {
        $request->setUserResolver(fn () => $user);
    }

    $middleware = app(ResolveTenantConnection::class);
    $middleware->handle($request, fn () => response('ok'));
}

test('middleware is a no-op for guest requests', function () {
    config([
        'database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION => null,
    ]);

    runMiddleware(null);

    expect(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION))->toBeNull();
});

test('middleware leaves the default connection untouched when no provider is configured', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $default = config('database.default');

    runMiddleware($user);

    expect(config('database.default'))->toBe($default);
});

test('middleware registers the tenant_custom connection when a global provider exists', function () {
    CloudProvider::factory()->postgres()->global()->create([
        'credentials' => [
            'host' => 'global-host.example',
            'port' => '5432',
            'database' => 'global_db',
            'username' => 'u',
            'password' => 'p',
            'sslmode' => 'disable',
        ],
    ]);

    $user = User::factory()->create(['organization_id' => null]);

    runMiddleware($user);

    expect(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.host'))
        ->toBe('global-host.example');
});

test('middleware prefers the tenant provider over the global one', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

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

    runMiddleware($user);

    expect(config('database.connections.'.CloudProviderService::RUNTIME_DB_CONNECTION.'.host'))
        ->toBe('tenant-host');
});
