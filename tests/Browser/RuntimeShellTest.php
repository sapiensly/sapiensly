<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

function shellApp(int $objects = 4): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);
    $app = App::factory()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'slug' => 'shell_'.Str::lower(Str::random(5)),
    ]);
    $names = ['Clientes', 'Oportunidades', 'Actividades', 'Contratos', 'Facturas', 'Incidencias', 'Proveedores', 'Inventario', 'Reportes'];
    $objs = [];
    $pages = [];
    foreach (array_slice($names, 0, $objects) as $name) {
        $id = 'obj_'.strtolower((string) Str::ulid());
        $fld = 'fld_'.strtolower((string) Str::ulid());
        $objs[] = ['id' => $id, 'slug' => Str::slug($name, '_'), 'name' => $name, 'fields' => [['id' => $fld, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']]];
        $pages[] = ['id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => Str::slug($name, '_'), 'path' => '/'.Str::slug($name, '_'), 'name' => $name,
            'blocks' => [['id' => 'blk_'.strtolower((string) Str::ulid()), 'type' => 'heading', 'content' => $name, 'level' => 2]]];
    }
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0', 'id' => $app->id, 'slug' => $app->slug, 'name' => 'Shell', 'version' => 1,
        'settings' => ['default_locale' => 'es-MX'], 'objects' => $objs, 'pages' => $pages,
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

it('aligns the brand with the page title it sits above', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = shellApp();
    visit("/r/{$app->slug}/clientes")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Contratos');
})->group('browser');

it('folds the navigation on a phone instead of dropping it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = shellApp(objects: 6);
    // Before this, below `sm` the nav simply was not rendered: five of the six
    // objects had no route to them on a phone.
    visit("/r/{$app->slug}/clientes")->on()->iPhone14Pro()
        ->assertNoJavaScriptErrors()
        ->assertSee('Menú')
        ->click('[data-sp-nav-menu]')
        ->assertSee('Incidencias');
})->group('browser');
