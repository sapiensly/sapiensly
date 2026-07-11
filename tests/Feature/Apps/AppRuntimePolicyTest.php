<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * End-to-end enforcement of the manifest permission model at the runtime render
 * surface: page access, navigation filtering, block-visibility stripping,
 * row_filter, and hidden-field stripping — all server-authoritative, exercised
 * as a non-bypass org member holding the default "user" role.
 */
function arp_id(string $prefix): string
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
        'slug' => 'policed',
        'name' => 'Policed App',
        'visibility' => 'organization',
    ]);

    $objId = arp_id('obj');
    $fldName = arp_id('fld');
    $fldSecret = arp_id('fld');
    $fldOwner = arp_id('fld');
    $rolAdmin = arp_id('rol');
    $rolUser = arp_id('rol');
    $pagHome = arp_id('pag');
    $pagAdmin = arp_id('pag');
    $blkTable = arp_id('blk');
    $blkAdminOnly = arp_id('blk');

    $this->ids = compact('objId', 'fldName', 'fldSecret', 'fldOwner', 'pagHome', 'pagAdmin', 'blkTable', 'blkAdminOnly');

    $cols = fn () => [
        ['id' => arp_id('col'), 'field_id' => $fldName],
        ['id' => arp_id('col'), 'field_id' => $fldSecret],
        ['id' => arp_id('col'), 'field_id' => $fldOwner],
    ];

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'policed',
        'name' => 'Policed App',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'items',
            'name' => 'Item',
            'fields' => [
                ['id' => $fldName, 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ['id' => $fldSecret, 'slug' => 'secret', 'name' => 'Secret', 'type' => 'string'],
                ['id' => $fldOwner, 'slug' => 'owner', 'name' => 'Owner', 'type' => 'string'],
            ],
        ]],
        'pages' => [
            [
                'id' => $pagHome, 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
                'blocks' => [
                    [
                        'id' => $blkTable, 'type' => 'table',
                        'data_source' => ['object_id' => $objId],
                        'columns' => $cols(),
                    ],
                    [
                        'id' => $blkAdminOnly, 'type' => 'table',
                        'visibility' => ['roles' => ['admin']],
                        'data_source' => ['object_id' => $objId],
                        'columns' => $cols(),
                    ],
                ],
            ],
            [
                'id' => $pagAdmin, 'slug' => 'admin', 'name' => 'Admin', 'path' => '/admin',
                'blocks' => [[
                    'id' => arp_id('blk'), 'type' => 'table',
                    'data_source' => ['object_id' => $objId],
                    'columns' => $cols(),
                ]],
            ],
        ],
        'permissions' => [
            'roles' => [
                ['id' => $rolAdmin, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => $rolUser, 'slug' => 'user', 'name' => 'User', 'is_default' => true],
            ],
            'object_policies' => [
                [
                    'object_id' => $objId, 'role_id' => $rolUser, 'actions' => ['read'],
                    'row_filter' => ['op' => 'eq', 'field_id' => $fldOwner, 'value_expression' => '{{current_user.id}}'],
                    'field_restrictions' => ['hidden' => [$fldSecret]],
                ],
                ['object_id' => $objId, 'role_id' => $rolAdmin, 'actions' => ['create', 'read', 'update', 'delete']],
            ],
            'page_policies' => [
                ['page_id' => $pagAdmin, 'role_id' => $rolAdmin, 'can_view' => true],
            ],
        ],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId,
        'data' => ['name' => 'Mine', 'secret' => 'xyz', 'owner' => $this->member->id]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId,
        'data' => ['name' => 'Theirs', 'secret' => 'abc', 'owner' => $this->owner->id]]);
});

it('hides an admin-only page from a default-role member (nav + 403)', function () {
    $this->actingAs($this->member)
        ->get('/r/policed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.slug', 'home')
            ->has('manifest.pages', 1) // only the home page survives the filter
            ->where('manifest.pages.0.slug', 'home'),
        );

    $this->actingAs($this->member)->get('/r/policed/admin')->assertForbidden();
});

it('strips a block whose visibility excludes the role, data and all', function () {
    $this->actingAs($this->member)
        ->get('/r/policed')
        ->assertInertia(fn ($page) => $page
            ->has('page.blocks', 1) // admin-only table dropped
            ->where('page.blocks.0.id', $this->ids['blkTable']),
        );

    deferredBlockData($this->actingAs($this->member), '/r/policed')
        ->assertJsonPath('props.blockData.'.$this->ids['blkTable'], fn ($v) => $v !== null)
        ->assertJsonMissingPath('props.blockData.'.$this->ids['blkAdminOnly']);
});

it('applies the row_filter and strips hidden fields for the member', function () {
    deferredBlockData($this->actingAs($this->member), '/r/policed')
        ->assertJsonCount(1, 'props.blockData.'.$this->ids['blkTable'].'.rows') // only the member's row
        ->assertJsonPath('props.blockData.'.$this->ids['blkTable'].'.rows.0.data.name', 'Mine')
        ->assertJsonPath('props.blockData.'.$this->ids['blkTable'].'.rows.0.data.owner', $this->member->id)
        ->assertJsonMissingPath('props.blockData.'.$this->ids['blkTable'].'.rows.0.data.secret');
});

it('grants the app owner full bypass over every policy', function () {
    $this->actingAs($this->owner)
        ->get('/r/policed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('manifest.pages', 2) // both pages visible
            ->has('page.blocks', 2), // admin-only block present
        );

    deferredBlockData($this->actingAs($this->owner), '/r/policed')
        ->assertJsonCount(2, 'props.blockData.'.$this->ids['blkTable'].'.rows') // no row_filter
        ->assertJsonPath('props.blockData.'.$this->ids['blkTable'].'.rows.0.data.secret', 'xyz'); // not hidden

    $this->actingAs($this->owner)->get('/r/policed/admin')->assertOk();
});
