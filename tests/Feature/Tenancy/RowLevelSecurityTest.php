<?php

use App\Enums\Visibility;
use App\Models\App;
use App\Models\Organization;
use App\Models\Record;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Asserts the database-level tenant isolation that the rest of the suite
 * bypasses (it runs the runtime connections as the owner). RLS only separates
 * rows between sessions, so the seed data must be COMMITTED and read back from a
 * second session that authenticates as the real least-privilege `tenant_app`
 * role. We therefore seed through a dedicated owner connection (`owner_commit`,
 * autocommit — outside RefreshDatabase's rolled-back transaction) and clean it
 * up afterwards.
 */
beforeEach(function () {
    Config::set('database.connections.owner_commit', Config::get('database.connections.pgsql'));
    Config::set('database.connections.tenant_app_real', array_merge(
        Config::get('database.connections.tenant'),
        ['username' => config('tenancy.tenant_role'), 'password' => 'x']
    ));
    DB::purge('owner_commit');
    DB::purge('tenant_app_real');
    truncateTenantFixtures();
});

afterEach(function () {
    truncateTenantFixtures();
});

function truncateTenantFixtures(): void
{
    DB::connection('owner_commit')->statement(
        'truncate tenant.records, platform.apps, platform.organizations, platform.users restart identity cascade'
    );
}

function scopeTenant(?string $orgId, ?int $userId): void
{
    DB::connection('tenant_app_real')->statement(
        'select set_config(?, ?, false), set_config(?, ?, false)',
        ['app.organization_id', $orgId ?? '', 'app.user_id', $userId === null ? '' : (string) $userId]
    );
}

function makeOwner(): User
{
    return User::on('owner_commit')->create([
        'name' => 'Owner', 'email' => uniqid('u').'@example.com', 'password' => 'secret',
    ]);
}

function makeApp(?string $orgId, int $userId): App
{
    return App::on('owner_commit')->create([
        'user_id' => $userId,
        'organization_id' => $orgId,
        'slug' => 'app-'.uniqid(),
        'name' => 'App',
        'visibility' => $orgId ? Visibility::Organization : Visibility::Private,
    ]);
}

function seedRecord(?string $orgId, ?int $userId, string $appId): Record
{
    return Record::on('owner_commit')->create([
        'organization_id' => $orgId,
        'user_id' => $userId,
        'app_id' => $appId,
        'object_definition_id' => 'obj',
        'data' => ['k' => 'v'],
    ]);
}

function tenantRecordCount(): int
{
    return DB::connection('tenant_app_real')->table('tenant.records')->count();
}

it('only returns rows for the scoped organization', function () {
    $user = makeOwner();
    $orgA = Organization::on('owner_commit')->create(['name' => 'A', 'slug' => 'a-'.uniqid()]);
    $orgB = Organization::on('owner_commit')->create(['name' => 'B', 'slug' => 'b-'.uniqid()]);
    $appA = makeApp($orgA->id, $user->id);
    $appB = makeApp($orgB->id, $user->id);

    seedRecord($orgA->id, null, $appA->id);
    seedRecord($orgA->id, null, $appA->id);
    seedRecord($orgB->id, null, $appB->id);

    scopeTenant($orgA->id, null);
    expect(tenantRecordCount())->toBe(2);

    scopeTenant($orgB->id, null);
    expect(tenantRecordCount())->toBe(1);
});

it('is fail-closed when no tenant scope is set', function () {
    $user = makeOwner();
    $org = Organization::on('owner_commit')->create(['name' => 'O', 'slug' => 'o-'.uniqid()]);
    seedRecord($org->id, null, makeApp($org->id, $user->id)->id);

    scopeTenant(null, null);
    expect(tenantRecordCount())->toBe(0);
});

it('scopes personal-mode rows to the owning user', function () {
    $userA = makeOwner();
    $userB = makeOwner();
    seedRecord(null, $userA->id, makeApp(null, $userA->id)->id);
    seedRecord(null, $userB->id, makeApp(null, $userB->id)->id);

    scopeTenant(null, $userA->id);
    expect(tenantRecordCount())->toBe(1);
});

it('blocks inserting a row for another tenant (WITH CHECK)', function () {
    $user = makeOwner();
    $orgA = Organization::on('owner_commit')->create(['name' => 'A', 'slug' => 'a-'.uniqid()]);
    $orgB = Organization::on('owner_commit')->create(['name' => 'B', 'slug' => 'b-'.uniqid()]);
    $appB = makeApp($orgB->id, $user->id);

    scopeTenant($orgA->id, null);

    expect(fn () => Record::on('tenant_app_real')->create([
        'organization_id' => $orgB->id,
        'app_id' => $appB->id,
        'object_definition_id' => 'obj',
        'data' => ['k' => 'v'],
    ]))->toThrow(QueryException::class);
});

it('auto-fills the tenant key from the session context on insert', function () {
    $user = makeOwner();
    $orgA = Organization::on('owner_commit')->create(['name' => 'A', 'slug' => 'a-'.uniqid()]);
    $appA = makeApp($orgA->id, $user->id);

    scopeTenant($orgA->id, null);

    // No organization_id passed — the BEFORE INSERT trigger fills it from the GUC,
    // so WITH CHECK passes and the row is scoped to orgA.
    Record::on('tenant_app_real')->create([
        'app_id' => $appA->id,
        'object_definition_id' => 'obj',
        'data' => ['k' => 'v'],
    ]);

    expect(tenantRecordCount())->toBe(1)
        ->and(DB::connection('owner_commit')->table('tenant.records')->value('organization_id'))
        ->toBe($orgA->id);
});

it('denies tenant_app any access to the platform schema', function () {
    expect(fn () => DB::connection('tenant_app_real')->select('select 1 from platform.users limit 1'))
        ->toThrow(QueryException::class);
});

it('exposes the tenant scope through TenantContext', function () {
    $context = app(TenantContext::class);
    $context->set('org_abc', 42);

    expect($context->organizationId())->toBe('org_abc')
        ->and($context->userId())->toBe(42)
        ->and($context->hasContext())->toBeTrue();
});
