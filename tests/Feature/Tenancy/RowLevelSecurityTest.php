<?php

use App\Enums\Visibility;
use App\Models\App;
use App\Models\KnowledgeBase;
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
});

afterEach(function () {
    deleteRlsFixtures();
});

/**
 * Ids this file committed on `owner_commit`, so cleanup can delete exactly those
 * rows and nothing else.
 */
function rlsFixtures(): object
{
    static $ids;

    return $ids ??= new class
    {
        /** @var array<int, int> */
        public array $users = [];

        /** @var array<int, string> */
        public array $orgs = [];

        /** @var array<int, string> */
        public array $apps = [];
    };
}

/**
 * Remove this file's committed fixtures.
 *
 * Deliberately NOT `truncate … cascade`, which is what this used to be. TRUNCATE
 * takes an AccessExclusiveLock on every table it names, so it blocks on — and
 * deadlocks with — any transaction holding those tables, including the
 * RefreshDatabase transaction this very test opened on the default connection.
 * The suite's intermittent red was full of exactly that ("Process N waits for
 * AccessExclusiveLock … blocked by process M"), and it named platform.users /
 * organizations / apps, so when it did win the race it deleted rows belonging to
 * whatever else was mid-run.
 *
 * Targeted deletes take row locks only, and touch nothing this file didn't
 * create. `whereIn` with an empty list compiles to `0 = 1`, so an empty registry
 * is a no-op rather than a table-wide delete.
 */
function deleteRlsFixtures(): void
{
    $ids = rlsFixtures();
    $db = DB::connection('owner_commit');

    $db->table('tenant.app_user_roles')->whereIn('app_id', $ids->apps)->delete();

    foreach (['tenant.records', 'tenant.knowledge_bases', 'tenant.chat_agents', 'tenant.ai_usage_events'] as $table) {
        $db->table($table)
            ->whereIn('organization_id', $ids->orgs)
            ->orWhereIn('user_id', $ids->users)
            ->delete();
    }
    // Personal-mode rows carry a null organization_id and a user_id, so the
    // clause above catches them; records additionally hang off an app.
    $db->table('tenant.records')->whereIn('app_id', $ids->apps)->delete();

    $db->table('platform.apps')->whereIn('id', $ids->apps)->delete();
    $db->table('platform.organizations')->whereIn('id', $ids->orgs)->delete();
    $db->table('platform.users')->whereIn('id', $ids->users)->delete();

    $ids->users = [];
    $ids->orgs = [];
    $ids->apps = [];
}

function makeRlsOrg(string $name): Organization
{
    $org = Organization::on('owner_commit')->create([
        'name' => $name,
        'slug' => strtolower($name).'-'.uniqid(),
    ]);
    rlsFixtures()->orgs[] = $org->id;

    return $org;
}

function seedChatAgent(?string $orgId, int $userId): void
{
    DB::connection('owner_commit')->table('tenant.chat_agents')->insert([
        'id' => 'cpar_'.uniqid(),
        'chat_id' => 'chat_'.uniqid(),
        'agent_id' => 'agent_'.uniqid(),
        'organization_id' => $orgId,
        'user_id' => $userId,
        'joined_at' => now(),
    ]);
}

function tenantChatAgentCount(): int
{
    return DB::connection('tenant_app_real')->table('tenant.chat_agents')->count();
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
    $user = User::on('owner_commit')->create([
        'name' => 'Owner', 'email' => uniqid('u').'@example.com', 'password' => 'secret',
    ]);
    rlsFixtures()->users[] = $user->id;

    return $user;
}

function makeApp(?string $orgId, int $userId): App
{
    $app = App::on('owner_commit')->create([
        'user_id' => $userId,
        'organization_id' => $orgId,
        'slug' => 'app-'.uniqid(),
        'name' => 'App',
        'visibility' => $orgId ? Visibility::Organization : Visibility::Private,
    ]);
    rlsFixtures()->apps[] = $app->id;

    return $app;
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

function seedKnowledgeBase(?string $orgId, int $userId): KnowledgeBase
{
    return KnowledgeBase::on('owner_commit')->create([
        'organization_id' => $orgId,
        'user_id' => $userId,
        'name' => 'KB '.uniqid(),
        'status' => 'ready',
        'visibility' => $orgId ? Visibility::Organization : Visibility::Private,
    ]);
}

function tenantKnowledgeBaseCount(): int
{
    return DB::connection('tenant_app_real')->table('tenant.knowledge_bases')->count();
}

it('only returns rows for the scoped organization', function () {
    $user = makeOwner();
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');
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
    $org = makeRlsOrg('O');
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
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');
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
    $orgA = makeRlsOrg('A');
    $appA = makeApp($orgA->id, $user->id);

    scopeTenant($orgA->id, null);

    // No organization_id passed — the BEFORE INSERT trigger fills it from the GUC,
    // so WITH CHECK passes and the row is scoped to orgA.
    Record::on('tenant_app_real')->create([
        'app_id' => $appA->id,
        'object_definition_id' => 'obj',
        'data' => ['k' => 'v'],
    ]);

    // Read back as the owner (RLS off) scoped to THIS app: the only assertion in
    // the file that bypasses the tenant role, so it is the only one that would
    // have read a leftover row from an earlier test rather than its own.
    expect(tenantRecordCount())->toBe(1)
        ->and(DB::connection('owner_commit')->table('tenant.records')->where('app_id', $appA->id)->value('organization_id'))
        ->toBe($orgA->id);
});

it('isolates knowledge_bases by organization under the real tenant role', function () {
    // Proves knowledge_bases was promoted from platform to the tenant schema:
    // reading it through the real tenant_app role only works if it now lives in
    // `tenant`, and the row-level isolation matches the rest of the tenant data.
    $user = makeOwner();
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');

    seedKnowledgeBase($orgA->id, $user->id);
    seedKnowledgeBase($orgA->id, $user->id);
    seedKnowledgeBase($orgB->id, $user->id);

    scopeTenant($orgA->id, null);
    expect(tenantKnowledgeBaseCount())->toBe(2);

    scopeTenant($orgB->id, null);
    expect(tenantKnowledgeBaseCount())->toBe(1);

    scopeTenant(null, null);
    expect(tenantKnowledgeBaseCount())->toBe(0);
});

it('isolates chat_agents by tenant under the real tenant role', function () {
    $userA = makeOwner();
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');

    seedChatAgent($orgA->id, $userA->id);
    seedChatAgent($orgA->id, $userA->id);
    seedChatAgent($orgB->id, $userA->id);

    scopeTenant($orgA->id, null);
    expect(tenantChatAgentCount())->toBe(2);

    scopeTenant($orgB->id, null);
    expect(tenantChatAgentCount())->toBe(1);

    scopeTenant(null, null);
    expect(tenantChatAgentCount())->toBe(0);
});

it('lets the tenant role insert AI usage events (sequence grant)', function () {
    // ai_usage_events uses a bigIncrements id, so the tenant role needs USAGE on
    // its sequence. The table was relocated to `tenant` after the schema-wide
    // sequence grant ran, so without an explicit grant every recorder INSERT
    // fails with "permission denied for sequence" — silently, since the recorder
    // swallows its errors, leaving both spend dashboards empty.
    $user = makeOwner();
    $org = makeRlsOrg('A');

    scopeTenant($org->id, $user->id);

    DB::connection('tenant_app_real')->table('tenant.ai_usage_events')->insert([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'module' => 'chat',
        'driver' => 'openai',
        'model' => 'gpt-x',
        'source' => 'own',
        'input_tokens' => 10,
        'output_tokens' => 5,
        'cost' => 0.01,
        'estimated' => false,
        'status' => 'success',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::connection('tenant_app_real')->table('tenant.ai_usage_events')->count())->toBe(1);
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

/* ---------------- app_user_roles (app-role grants) ---------------- */

function seedAppUserRole(?string $orgId, ?int $userId, string $appId, int $assignedUserId, string $roleSlug = 'user'): void
{
    DB::connection('owner_commit')->table('tenant.app_user_roles')->insert([
        'id' => 'aur_'.uniqid(),
        'organization_id' => $orgId,
        'user_id' => $userId,
        'app_id' => $appId,
        'assigned_user_id' => $assignedUserId,
        'role_slug' => $roleSlug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tenantAppUserRoleCount(): int
{
    return DB::connection('tenant_app_real')->table('tenant.app_user_roles')->count();
}

it('isolates app_user_roles by organization', function () {
    $owner = makeOwner();
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');
    $appA = makeApp($orgA->id, $owner->id);
    $appB = makeApp($orgB->id, $owner->id);
    $member = makeOwner();

    seedAppUserRole($orgA->id, null, $appA->id, $member->id, 'admin');
    seedAppUserRole($orgA->id, null, $appA->id, $owner->id, 'user');
    seedAppUserRole($orgB->id, null, $appB->id, $member->id, 'user');

    scopeTenant($orgA->id, null);
    expect(tenantAppUserRoleCount())->toBe(2);

    scopeTenant($orgB->id, null);
    expect(tenantAppUserRoleCount())->toBe(1);
});

it('is fail-closed for app_user_roles when no tenant scope is set', function () {
    $owner = makeOwner();
    $org = makeRlsOrg('O');
    seedAppUserRole($org->id, null, makeApp($org->id, $owner->id)->id, $owner->id);

    scopeTenant(null, null);
    expect(tenantAppUserRoleCount())->toBe(0);
});

/* ---------------- app_templates (an org's saved starter apps) ---------------- */

function seedAppTemplate(?string $orgId, ?int $userId, string $name): void
{
    DB::connection('owner_commit')->table('tenant.app_templates')->insert([
        'id' => 'tpl_'.uniqid(),
        'organization_id' => $orgId,
        'user_id' => $userId,
        'name' => $name,
        'kind' => 'app',
        'package' => json_encode(['format' => 'sapiensly.app-package', 'manifest' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tenantAppTemplateCount(): int
{
    return DB::connection('tenant_app_real')->table('tenant.app_templates')->count();
}

it('isolates app_templates by organization', function () {
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');

    // A saved template carries a whole manifest — and optionally rows — from an
    // app someone built. Another tenant reading one would be reading their work.
    seedAppTemplate($orgA->id, null, 'CRM de A');
    seedAppTemplate($orgA->id, null, 'Pedidos de A');
    seedAppTemplate($orgB->id, null, 'Algo de B');

    scopeTenant($orgA->id, null);
    expect(tenantAppTemplateCount())->toBe(2);

    scopeTenant($orgB->id, null);
    expect(tenantAppTemplateCount())->toBe(1);
});

it('is fail-closed for app_templates when no tenant scope is set', function () {
    $org = makeRlsOrg('O');
    seedAppTemplate($org->id, null, 'Cualquiera');

    scopeTenant(null, null);
    expect(tenantAppTemplateCount())->toBe(0);
});

/* ---------------- the scope a scheduled command has to establish ---------------- */

function seedAppExport(?string $orgId, ?int $userId, string $appId): void
{
    DB::connection('owner_commit')->table('tenant.app_exports')->insert([
        'id' => 'exp_'.uniqid(),
        'organization_id' => $orgId,
        'user_id' => $userId,
        'app_id' => $appId,
        'object_id' => 'obj_'.uniqid(),
        'format' => 'csv',
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tenantAppExportCount(): int
{
    return DB::connection('tenant_app_real')->table('tenant.app_exports')->count();
}

it('shows a scheduled command nothing until it scopes itself to a tenant', function () {
    $owner = makeOwner();
    $orgA = makeRlsOrg('A');
    $orgB = makeRlsOrg('B');

    seedAppExport($orgA->id, null, makeApp($orgA->id, $owner->id)->id);
    seedAppExport($orgA->id, null, makeApp($orgA->id, $owner->id)->id);
    seedAppExport($orgB->id, null, makeApp($orgB->id, $owner->id)->id);

    // This is the failure mode the sweeper is built around, and the one that
    // let a pruner report "0 pruned" for months while looking healthy: with no
    // scope the tenant role sees NOTHING, so the work silently does not happen.
    scopeTenant(null, null);
    expect(tenantAppExportCount())->toBe(0);

    // Scoped per tenant, the rows are there — and only that tenant's.
    scopeTenant($orgA->id, null);
    expect(tenantAppExportCount())->toBe(2);

    scopeTenant($orgB->id, null);
    expect(tenantAppExportCount())->toBe(1);
});
