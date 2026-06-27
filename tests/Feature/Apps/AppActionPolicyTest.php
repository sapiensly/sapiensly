<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\AppUserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Server-authoritative enforcement at the write surface (POST /r/{app}/actions):
 * the object CRUD grant, read-only field rejection, and the row_filter write
 * re-check that turns an out-of-scope update/delete target into a not-found.
 * Exercised as a non-bypass org member on the default "user" role.
 */
function aap_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);

    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);

    $this->member = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->member->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'slug' => 'wpoliced',
        'name' => 'Write Policed',
        'visibility' => 'organization',
    ]);

    $this->objId = aap_id('obj');
    $fldName = aap_id('fld');
    $fldOwner = aap_id('fld');
    $rolAdmin = aap_id('rol');
    $rolUser = aap_id('rol');
    $rolEditor = aap_id('rol');

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'wpoliced',
        'name' => 'Write Policed',
        'version' => 1,
        'objects' => [[
            'id' => $this->objId,
            'slug' => 'items',
            'name' => 'Item',
            'fields' => [
                ['id' => $fldName, 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ['id' => $fldOwner, 'slug' => 'owner', 'name' => 'Owner', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => $rolAdmin, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => $rolUser, 'slug' => 'user', 'name' => 'User', 'is_default' => true],
                ['id' => $rolEditor, 'slug' => 'editor', 'name' => 'Editor', 'is_default' => false],
            ],
            'object_policies' => [
                // user: read-only, scoped to rows they own.
                [
                    'object_id' => $this->objId, 'role_id' => $rolUser, 'actions' => ['read'],
                    'row_filter' => ['op' => 'eq', 'field_id' => $fldOwner, 'value_expression' => '{{current_user.id}}'],
                ],
                // editor: full CRUD but row-scoped + name is read-only.
                [
                    'object_id' => $this->objId, 'role_id' => $rolEditor,
                    'actions' => ['create', 'read', 'update', 'delete'],
                    'row_filter' => ['op' => 'eq', 'field_id' => $fldOwner, 'value_expression' => '{{current_user.id}}'],
                    'field_restrictions' => ['readonly' => [$fldName]],
                ],
                ['object_id' => $this->objId, 'role_id' => $rolAdmin, 'actions' => ['create', 'read', 'update', 'delete']],
            ],
        ],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);

    $this->mine = Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->objId,
        'data' => ['name' => 'Mine', 'owner' => $this->member->id]]);
    $this->theirs = Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->objId,
        'data' => ['name' => 'Theirs', 'owner' => $this->owner->id]]);
});

it('rejects a create the role is not granted (read-only user)', function () {
    $this->actingAs($this->member)
        ->postJson('/r/wpoliced/actions', [
            'actions' => [['type' => 'create_record', 'object_id' => $this->objId, 'values' => ['name' => 'X']]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Record::query()->where('app_id', $this->testApp->id)->count())->toBe(2);
});

it('rejects a write to a read-only field', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'editor',
    ]);

    $this->actingAs($this->member)
        ->postJson('/r/wpoliced/actions', [
            'actions' => [[
                'type' => 'update_record', 'object_id' => $this->objId,
                'record_id_expression' => '{{row.id}}',
                'values' => ['name' => 'Renamed'],
            ]],
            'row' => ['id' => $this->mine->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Record::query()->find($this->mine->id)->data['name'])->toBe('Mine');
});

it('treats an update of an out-of-row_filter record as not-found', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'editor',
    ]);

    // 'owner' is not read-only for the editor, so the write itself is allowed —
    // but the target row belongs to someone else, so the filtered finder misses it.
    $this->actingAs($this->member)
        ->postJson('/r/wpoliced/actions', [
            'actions' => [[
                'type' => 'update_record', 'object_id' => $this->objId,
                'record_id_expression' => '{{row.id}}',
                'values' => ['owner' => $this->member->id],
            ]],
            'row' => ['id' => $this->theirs->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Record::query()->find($this->theirs->id)->data['owner'])->toBe($this->owner->id);
});

it('allows the granted editor to update their own row', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'editor',
    ]);

    $this->actingAs($this->member)
        ->postJson('/r/wpoliced/actions', [
            'actions' => [[
                'type' => 'update_record', 'object_id' => $this->objId,
                'record_id_expression' => '{{row.id}}',
                'values' => ['owner' => $this->member->id],
            ]],
            'row' => ['id' => $this->mine->id],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('lets the app owner bypass write policies', function () {
    $this->actingAs($this->owner)
        ->postJson('/r/wpoliced/actions', [
            'actions' => [['type' => 'create_record', 'object_id' => $this->objId, 'values' => ['name' => 'New', 'owner' => $this->owner->id]]],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Record::query()->where('app_id', $this->testApp->id)->count())->toBe(3);
});
