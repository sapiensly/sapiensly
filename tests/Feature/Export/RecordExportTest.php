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
 * Downloading records. The governing rule is that an export can never return
 * more than the screen showed — a file that quietly carried a hidden column or
 * a colleague's rows would be the least visible way around a permission in the
 * product, so every test here is about what does NOT come out.
 */
function expId(string $prefix): string
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
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'ventas', 'name' => 'Ventas', 'visibility' => 'organization',
    ]);

    $this->ids = [
        'obj' => expId('obj'), 'objOtro' => expId('obj'),
        'fldNombre' => expId('fld'), 'fldMargen' => expId('fld'), 'fldDuenio' => expId('fld'),
        'fldSecreto' => expId('fld'),
        'rolAdmin' => expId('rol'), 'rolUser' => expId('rol'),
    ];

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id, 'slug' => 'ventas', 'name' => 'Ventas', 'version' => 1,
        'objects' => [
            [
                'id' => $this->ids['obj'], 'slug' => 'pedidos', 'name' => 'Pedido',
                'fields' => [
                    ['id' => $this->ids['fldNombre'], 'slug' => 'cliente', 'name' => 'Cliente', 'type' => 'string'],
                    ['id' => $this->ids['fldMargen'], 'slug' => 'margen', 'name' => 'Margen', 'type' => 'number'],
                    ['id' => $this->ids['fldDuenio'], 'slug' => 'duenio', 'name' => 'Dueño', 'type' => 'string'],
                ],
            ],
            [
                'id' => $this->ids['objOtro'], 'slug' => 'secretos', 'name' => 'Secreto',
                'fields' => [['id' => $this->ids['fldSecreto'], 'slug' => 'nota', 'name' => 'Nota', 'type' => 'string']],
            ],
        ],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => $this->ids['rolAdmin'], 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => $this->ids['rolUser'], 'slug' => 'user', 'name' => 'User', 'is_default' => true],
            ],
            'object_policies' => [
                [
                    'object_id' => $this->ids['obj'], 'role_id' => $this->ids['rolUser'], 'actions' => ['read'],
                    'row_filter' => ['op' => 'eq', 'field_id' => $this->ids['fldDuenio'], 'value_expression' => '{{current_user.id}}'],
                    'field_restrictions' => ['hidden' => [$this->ids['fldMargen']]],
                ],
                ['object_id' => $this->ids['obj'], 'role_id' => $this->ids['rolAdmin'], 'actions' => ['create', 'read', 'update', 'delete']],
                ['object_id' => $this->ids['objOtro'], 'role_id' => $this->ids['rolAdmin'], 'actions' => ['read']],
            ],
        ],
    ], $this->owner);

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['obj'],
        'data' => ['cliente' => 'Acme', 'margen' => 42, 'duenio' => (string) $this->member->id]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['obj'],
        'data' => ['cliente' => 'Globex', 'margen' => 7, 'duenio' => (string) $this->owner->id]]);
});

/** Pull the streamed body out, BOM stripped. */
function csvBody($response): string
{
    ob_start();
    $response->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

it('downloads what the owner sees, headers and all', function () {
    $body = csvBody($this->actingAs($this->owner)->get('/r/ventas/objects/pedidos/export'));

    expect($body)->toContain('Cliente,Margen,Dueño')
        ->and($body)->toContain('Acme')
        ->and($body)->toContain('Globex');
});

it('never exports a row or a column the role cannot see', function () {
    $body = csvBody($this->actingAs($this->member)->get('/r/ventas/objects/pedidos/export'));

    // The hidden column is absent from the HEADER, not blanked in the rows —
    // a blank column still tells you the field exists.
    expect($body)->not->toContain('Margen')
        ->and($body)->not->toContain('42');

    // The row_filter applies: the member owns one of the two rows.
    expect($body)->toContain('Acme')
        ->and($body)->not->toContain('Globex');
});

it('refuses an object the role may not read, the same way it refuses a missing one', function () {
    // Same 404 for both, so a download URL cannot map which objects exist.
    $this->actingAs($this->member)->get('/r/ventas/objects/secretos/export')->assertNotFound();
    $this->actingAs($this->member)->get('/r/ventas/objects/fantasma/export')->assertNotFound();
});

it('honours the role preview, so an admin can check what a role would get', function () {
    $body = csvBody($this->actingAs($this->owner)->get('/r/ventas/objects/pedidos/export?as_role=user'));

    // The owner bypasses everything — until they ask to look as `user`, and
    // then the export narrows exactly as the screen does: the hidden column is
    // gone, and the row_filter scopes to the PREVIEWER's own id, so they get
    // their row and not the member's.
    expect($body)->not->toContain('Margen')
        ->and($body)->toContain('Globex')
        ->and($body)->not->toContain('Acme');
});

it('keeps another organization out entirely', function () {
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)->get('/r/ventas/objects/pedidos/export')->assertNotFound();
});

it('serves a spreadsheet when asked for one', function () {
    $response = $this->actingAs($this->owner)->get('/r/ventas/objects/pedidos/export?format=xlsx');

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
});

it('flattens a multi-value cell into something a spreadsheet can hold', function () {
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['obj'],
        'data' => ['cliente' => 'Initech', 'margen' => 1, 'duenio' => (string) $this->owner->id]]);

    $body = csvBody($this->actingAs($this->owner)->get('/r/ventas/objects/pedidos/export'));

    // No JSON braces anywhere: a cell is a value a person can read.
    expect($body)->not->toContain('{')
        ->and($body)->not->toContain('[');
});
