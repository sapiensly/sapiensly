<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

it('shows the help a manifest wrote, and lets an error take its place', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);
    $obj = 'obj_'.strtolower((string) Str::ulid());
    $spec = [
        ['Folio', 'string', 'Como aparece en el recibo impreso'],
        ['Nombre completo', 'string', null],
        ['Correo', 'email', null],
        ['Teléfono', 'phone', null],
        ['Monto', 'currency', null],
        ['Cantidad', 'number', null],
        ['Fecha de alta', 'date', null],
        ['Descripción', 'long_text', 'Todo lo que el técnico deba saber antes de ir'],
        ['Notas', 'string', null],
        ['Referencia', 'string', null],
    ];
    $fields = [];
    $formFields = [];
    foreach ($spec as $i => [$name,$type,$help]) {
        $id = 'fld_'.strtolower((string) Str::ulid());
        $f = ['id' => $id, 'slug' => 'f'.$i, 'name' => $name, 'type' => $type];
        if ($help !== null) {
            $f['help_text'] = $help;
        }
        if ($type === 'currency') {
            $f['currency_code'] = 'MXN';
        }
        if ($i === 1) {
            $f['required'] = true;
        }
        $fields[] = $f;
        $formFields[] = ['field_id' => $id];
    }
    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'slug' => 'f_'.Str::lower(Str::random(5))]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0', 'id' => $app->id, 'slug' => $app->slug, 'name' => 'F', 'version' => 1,
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
        'objects' => [['id' => $obj, 'slug' => 'items', 'name' => 'Items', 'fields' => $fields]],
        'pages' => [['id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => 'nuevo', 'path' => '/nuevo', 'name' => 'Nuevo',
            'blocks' => [['id' => 'blk_form0001', 'type' => 'form', 'object_id' => $obj, 'mode' => 'create',
                'fields' => $formFields, 'submit_label' => 'Guardar',
                'on_submit' => [['type' => 'create_record', 'object_id' => $obj, 'values' => ['f0' => '{{form.f0}}']]]]]]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);
    // The help text a manifest can set had nowhere to render at all, and an
    // error appearing pushed everything below it down the page. Both now share
    // one reserved line under the field.
    visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Como aparece en el recibo impreso')
        ->assertSee('Todo lo que el técnico deba saber antes de ir')
        // Ten fields lay out in two columns, so the submit is reachable
        // without scrolling past a column thirteen rows tall.
        ->assertScript(
            "document.querySelectorAll('form .grid').length === 1"
        );
})->group('browser');
