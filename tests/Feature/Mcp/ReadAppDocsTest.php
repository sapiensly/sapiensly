<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ReadAppDocsTool;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The technical sheet, over MCP.
 *
 * The reason this tool exists rather than "just read the manifest": a manifest
 * is a graph flattened into JSON, and reading one means holding four id-to-name
 * maps in your head. The sheet has already done that join, in a fraction of the
 * tokens.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);

    $this->built = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'rentas-'.Str::lower(Str::random(6)),
        'name' => 'Rentas',
    ]);

    $version = AppVersion::create([
        'id' => 'ver_'.strtolower((string) Str::ulid()),
        'app_id' => $this->built->id,
        'version_number' => 4,
        'created_by' => $this->user->id,
        'manifest' => [
            'schema_version' => '1.0.0',
            'id' => $this->built->id,
            'slug' => $this->built->slug,
            'name' => 'Rentas',
            'description' => 'Lleva contratos de renta y sus pagos.',
            'version' => 4,
            'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
            'objects' => [[
                'id' => 'obj_leases',
                'name' => 'Contratos',
                'slug' => 'leases',
                'fields' => [
                    ['id' => 'fld_folio', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'required' => true],
                    ['id' => 'fld_payments', 'name' => 'Pagos', 'slug' => 'payments', 'type' => 'relation', 'cardinality' => 'one_to_many', 'target_object_id' => 'obj_payments', 'inverse_field_id' => 'fld_lease'],
                ],
            ], [
                'id' => 'obj_payments',
                'name' => 'Pagos',
                'slug' => 'payments',
                'fields' => [
                    ['id' => 'fld_amount', 'name' => 'Monto', 'slug' => 'amount', 'type' => 'currency'],
                    ['id' => 'fld_lease', 'name' => 'contrato', 'slug' => 'contrato', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_leases', 'inverse_field_id' => 'fld_payments', 'on_delete' => 'set_null'],
                ],
            ]],
            'pages' => [[
                'id' => 'pag_leases',
                'name' => 'Contratos',
                'slug' => 'leases',
                'path' => '/leases',
                'blocks' => [['id' => 'blk_table', 'type' => 'table', 'object_id' => 'obj_leases', 'columns' => [['id' => 'col_folio', 'field_id' => 'fld_folio']]]],
            ]],
            'permissions' => ['roles' => [['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin']]],
        ],
    ]);

    $this->built->update(['current_version_id' => $version->id]);
    $this->built->refresh();
});

it('returns the technical sheet by default', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ReadAppDocsTool::class, ['app_slug' => $this->built->slug])
        ->assertOk()
        ->assertSee('Ficha t')
        ->assertSee('/objects/0')
        // The ids joined up to their names — the reason this beats reading the
        // raw manifest.
        ->assertSee('Pagos (payments) | contrato (contrato) | many_to_one | Contratos (leases)')
        // The default is the technical one: the caller is a model about to
        // change something, not somebody learning to use the app.
        ->assertDontSee('Manual de uso');
});

it('returns both when asked for both', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ReadAppDocsTool::class, ['app_slug' => $this->built->slug, 'document' => 'both'])
        ->assertOk()
        ->assertSee('Manual de uso')
        ->assertSee('Ficha t');
});

it('says so plainly when the app has no version yet', function () {
    $empty = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'sin-version',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ReadAppDocsTool::class, ['app_slug' => $empty->slug])
        ->assertHasErrors();
});

it('cannot document an app the caller cannot see', function () {
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    SapiensServer::actingAs($stranger)
        ->tool(ReadAppDocsTool::class, ['app_slug' => $this->built->slug])
        ->assertHasErrors();
});
