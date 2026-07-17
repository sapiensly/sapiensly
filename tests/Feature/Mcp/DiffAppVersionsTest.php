<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\DiffAppVersionsTool;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'diff_target',
    ]);
});

function diffVersion(string $appId, int $number, array $objects, ?string $summary = null): AppVersion
{
    return AppVersion::factory()->create([
        'app_id' => $appId,
        'version_number' => $number,
        'change_summary' => $summary,
        'manifest' => [
            'schema_version' => '1.0.0',
            'version' => $number,
            'objects' => $objects,
            'pages' => [],
            'permissions' => ['roles' => []],
            'settings' => [],
        ],
    ]);
}

it('defaults to diffing the previous version against the current one', function () {
    diffVersion($this->testApp->id, 1, []);
    $v2 = diffVersion($this->testApp->id, 2, [
        ['id' => 'obj_a', 'slug' => 'clients', 'name' => 'Clients', 'fields' => [
            ['id' => 'fld_a', 'slug' => 'name', 'type' => 'string'],
        ]],
    ], 'Added Clients');
    $this->testApp->update(['current_version_id' => $v2->id]);

    SapiensServer::actingAs($this->user)
        ->tool(DiffAppVersionsTool::class, ['app_slug' => 'diff_target'])
        ->assertOk()
        ->assertSee('"version_number":1')
        ->assertSee('"version_number":2')
        ->assertSee('"objects_added":1')
        ->assertSee('clients');
});

it('diffs an explicit version pair', function () {
    diffVersion($this->testApp->id, 1, []);
    diffVersion($this->testApp->id, 2, [
        ['id' => 'obj_a', 'slug' => 'clients', 'name' => 'Clients', 'fields' => []],
    ]);
    $v3 = diffVersion($this->testApp->id, 3, [
        ['id' => 'obj_a', 'slug' => 'clients', 'name' => 'Clients', 'fields' => []],
        ['id' => 'obj_b', 'slug' => 'invoices', 'name' => 'Invoices', 'fields' => []],
    ]);
    $this->testApp->update(['current_version_id' => $v3->id]);

    SapiensServer::actingAs($this->user)
        ->tool(DiffAppVersionsTool::class, ['app_slug' => 'diff_target', 'from' => 1, 'to' => 3])
        ->assertOk()
        ->assertSee('"objects_added":2')
        ->assertSee('invoices');
});

it('rejects an out-of-order version pair', function () {
    diffVersion($this->testApp->id, 1, []);
    diffVersion($this->testApp->id, 2, []);

    SapiensServer::actingAs($this->user)
        ->tool(DiffAppVersionsTool::class, ['app_slug' => 'diff_target', 'from' => 2, 'to' => 1])
        ->assertSee('must be an earlier version');
});

it('explains that nothing precedes version 1', function () {
    $v1 = diffVersion($this->testApp->id, 1, []);
    $this->testApp->update(['current_version_id' => $v1->id]);

    SapiensServer::actingAs($this->user)
        ->tool(DiffAppVersionsTool::class, ['app_slug' => 'diff_target'])
        ->assertSee('Nothing precedes version 1');
});

it('rejects an app the caller cannot see', function () {
    SapiensServer::actingAs($this->user)
        ->tool(DiffAppVersionsTool::class, ['app_slug' => 'not_mine'])
        ->assertSee('is visible to you');
});
