<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeOrgWithOwner(): array
{
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $owner = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

    return [$org, $owner];
}

function makeMember(Organization $org): User
{
    $member = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $member->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    return $member;
}

function storagePayload(): array
{
    return [
        'driver' => 's3',
        'credentials' => [
            'bucket' => 'tenant-bucket',
            'region' => 'us-east-1',
            'key' => 'AKIATENANT',
            'secret' => 'tenant-secret',
        ],
    ];
}

function databasePayload(): array
{
    return [
        'driver' => 'postgresql',
        'credentials' => [
            'host' => 'tenant.db',
            'port' => '5432',
            'database' => 'tenant_db',
            'username' => 'tenant',
            'password' => 'pw',
            'sslmode' => 'require',
        ],
    ];
}

test('user without an organization cannot access cloud-providers index', function () {
    $user = User::factory()->create(['organization_id' => null]);

    actingAs($user)->get('/system/cloud-providers')->assertForbidden();
});

test('index marks canManage=true for the org owner', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)
        ->get('/system/cloud-providers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/CloudProviders')
            ->where('canManage', true)
            ->where('tenant.storage', null)
            ->where('tenant.database', null)
        );
});

test('index marks canManage=false for a regular org member', function () {
    [$org] = makeOrgWithOwner();
    $member = makeMember($org);

    actingAs($member)
        ->get('/system/cloud-providers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false));
});

test('index marks canManage=true for a sysadmin viewing any tenant', function () {
    [$org] = makeOrgWithOwner();
    $sysadminInOrg = makeMember($org);
    $sysadminInOrg->assignRole('sysadmin');

    actingAs($sysadminInOrg)
        ->get('/system/cloud-providers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', true));
});

test('index exposes the global fallback when the tenant has no override', function () {
    [$org, $owner] = makeOrgWithOwner();
    CloudProvider::factory()->storage()->global()->create();

    actingAs($owner)
        ->get('/system/cloud-providers')
        ->assertInertia(fn ($page) => $page
            ->where('tenant.storage', null)
            ->where('global.storage.driver', 's3')
        );
});

test('owner can save a tenant storage override', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)
        ->post('/system/cloud-providers/storage', storagePayload())
        ->assertRedirect('/system/cloud-providers');

    $provider = CloudProvider::where('organization_id', $org->id)
        ->where('kind', 'storage')
        ->first();

    expect($provider)->not->toBeNull()
        ->and($provider->visibility)->toBe(Visibility::Organization)
        ->and($provider->credentials['bucket'])->toBe('tenant-bucket')
        ->and($provider->credentials['secret'])->toBe('tenant-secret')
        ->and(CloudProvider::where('visibility', Visibility::Global)->count())->toBe(0);
});

test('owner can save a tenant database override', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)
        ->post('/system/cloud-providers/database', databasePayload())
        ->assertRedirect('/system/cloud-providers');

    $provider = CloudProvider::where('organization_id', $org->id)
        ->where('kind', 'database')
        ->first();

    expect($provider)->not->toBeNull()
        ->and($provider->driver)->toBe('postgresql')
        ->and($provider->credentials['password'])->toBe('pw');
});

test('regular member cannot save cloud providers', function () {
    [$org] = makeOrgWithOwner();
    $member = makeMember($org);

    actingAs($member)
        ->post('/system/cloud-providers/storage', storagePayload())
        ->assertForbidden();

    expect(CloudProvider::count())->toBe(0);
});

test('sysadmin can save cloud providers on behalf of any tenant', function () {
    [$org] = makeOrgWithOwner();
    $sysadmin = makeMember($org);
    $sysadmin->assignRole('sysadmin');

    actingAs($sysadmin)
        ->post('/system/cloud-providers/storage', storagePayload())
        ->assertRedirect('/system/cloud-providers');

    expect(CloudProvider::where('organization_id', $org->id)->count())->toBe(1);
});

test('saving the same kind twice replaces the tenant override', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)->post('/system/cloud-providers/storage', storagePayload());

    actingAs($owner)->post('/system/cloud-providers/storage', [
        'driver' => 'r2',
        'credentials' => [
            'bucket' => 'new-bucket',
            'region' => 'auto',
            'key' => 'k', 'secret' => 's', 'endpoint' => 'https://r2.x',
        ],
    ])->assertRedirect('/system/cloud-providers');

    $providers = CloudProvider::where('organization_id', $org->id)
        ->where('kind', 'storage')
        ->get();

    expect($providers)->toHaveCount(1)
        ->and($providers->first()->driver)->toBe('r2')
        ->and($providers->first()->credentials['bucket'])->toBe('new-bucket');
});

test('owner can remove a tenant override via destroy', function () {
    [$org, $owner] = makeOrgWithOwner();
    CloudProvider::factory()->storage()->forOrganization($org, $owner)->create();

    actingAs($owner)
        ->delete('/system/cloud-providers/storage')
        ->assertRedirect('/system/cloud-providers');

    expect(CloudProvider::where('organization_id', $org->id)->count())->toBe(0);
});

test('destroy does not touch the global provider', function () {
    [$org, $owner] = makeOrgWithOwner();
    CloudProvider::factory()->storage()->forOrganization($org, $owner)->create();
    CloudProvider::factory()->storage()->global()->create();

    actingAs($owner)->delete('/system/cloud-providers/storage');

    expect(CloudProvider::where('visibility', Visibility::Global)->count())->toBe(1)
        ->and(CloudProvider::where('organization_id', $org->id)->count())->toBe(0);
});

test('destroy with an invalid kind returns 404', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)->delete('/system/cloud-providers/bogus')->assertNotFound();
});

test('member cannot destroy tenant providers', function () {
    [$org, $owner] = makeOrgWithOwner();
    $member = makeMember($org);
    CloudProvider::factory()->storage()->forOrganization($org, $owner)->create();

    actingAs($member)->delete('/system/cloud-providers/storage')->assertForbidden();

    expect(CloudProvider::where('organization_id', $org->id)->count())->toBe(1);
});

test('tenant override takes precedence over global when both exist (Inertia props)', function () {
    [$org, $owner] = makeOrgWithOwner();
    CloudProvider::factory()->storage()->global()->create();
    $tenant = CloudProvider::factory()->storage()->forOrganization($org, $owner)->create([
        'credentials' => ['bucket' => 'visible-tenant', 'region' => 'eu', 'key' => 'k', 'secret' => 's'],
    ]);

    actingAs($owner)
        ->get('/system/cloud-providers')
        ->assertInertia(fn ($page) => $page
            ->where('tenant.storage.id', $tenant->id)
            ->where('tenant.storage.masked_credentials.bucket', 'visible-tenant')
            ->has('global.storage')
        );
});

test('validation fails when a required credential is missing', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)->post('/system/cloud-providers/storage', [
        'driver' => 's3',
        'credentials' => ['bucket' => 'only-bucket'],
    ])->assertSessionHasErrors([
        'credentials.region',
        'credentials.key',
        'credentials.secret',
    ]);
});

test('tenant cross-leak: saving in org A does not create rows for org B', function () {
    [$orgA, $ownerA] = makeOrgWithOwner();
    [$orgB] = makeOrgWithOwner();

    actingAs($ownerA)->post('/system/cloud-providers/storage', storagePayload());

    expect(CloudProvider::where('organization_id', $orgA->id)->count())->toBe(1)
        ->and(CloudProvider::where('organization_id', $orgB->id)->count())->toBe(0);
});

test('non-owner test-connection endpoint is forbidden for members', function () {
    [$org] = makeOrgWithOwner();
    $member = makeMember($org);

    actingAs($member)
        ->postJson('/system/cloud-providers/storage/test-connection', ['use_saved' => true])
        ->assertForbidden();
});

test('owner test-connection without a saved provider returns an informative failure', function () {
    [$org, $owner] = makeOrgWithOwner();

    actingAs($owner)
        ->postJson('/system/cloud-providers/storage/test-connection', ['use_saved' => true])
        ->assertOk()
        ->assertJson(['success' => false]);
});
