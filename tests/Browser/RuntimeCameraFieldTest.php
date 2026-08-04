<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A photo taken on the spot, rather than a file found on a disk.
 *
 * The first of the field-work capabilities, and deliberately an OPTION on the
 * existing `file` field rather than a type of its own: the value, the upload,
 * the storage and the display are all identical, and a new type would have
 * meant twenty-three files touched to say the same thing twice.
 *
 * It hands over to the browser's own capture attribute instead of opening a
 * camera we built. On a phone that gives the OS camera app — which focuses,
 * exposes and compresses better than anything here, and asks permission in the
 * way the person already recognises. On a desktop the attribute is ignored and
 * the picker opens, which is the whole reason this is safe to author: the field
 * is never a dead end on the wrong device.
 */
function cameraFieldApp(bool $withCamera): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $foto = 'fld_'.strtolower((string) Str::ulid());
    $contrato = 'fld_'.strtolower((string) Str::ulid());

    $fotoField = ['id' => $foto, 'slug' => 'foto', 'name' => 'Foto del daño', 'type' => 'file'];
    if ($withCamera) {
        $fotoField['capture'] = 'camera';
    }

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'cam_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Partes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'partes',
            'name' => 'Partes',
            'fields' => [
                $fotoField,
                // An ordinary file field beside it: the two must not become the
                // same control.
                ['id' => $contrato, 'slug' => 'contrato', 'name' => 'Contrato', 'type' => 'file'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'nuevo',
            'path' => '/nuevo',
            'name' => 'Nuevo parte',
            'blocks' => [[
                'id' => 'blk_form0001',
                'type' => 'form',
                'object_id' => $obj,
                'mode' => 'create',
                'fields' => [['field_id' => $foto], ['field_id' => $contrato]],
                'submit_label' => 'Guardar',
                'on_submit' => [['type' => 'create_record', 'object_id' => $obj, 'values' => ['foto' => '{{form.foto}}']]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

it('asks the device for a photo, and says so in the app language', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = cameraFieldApp(withCamera: true);

    visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // The label is the app's, not the platform's.
        ->assertSee('Tomar una foto')
        ->assertSee('Abre la cámara en el móvil')
        // `capture` is what hands the phone over to its own camera app.
        ->assertScript(
            'document.querySelectorAll("input[type=file][capture=environment]").length',
            1,
        )
        // And a camera field is asking for a picture, so it says so.
        ->assertScript(
            'document.querySelector("input[type=file][capture=environment]").accept',
            'image/*',
        );
})->group('browser');

it('leaves an ordinary file field exactly as it was', function () {
    // Two file fields on one form: the option must change one control and not
    // the other, or every existing app's attachments turn into camera prompts.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = cameraFieldApp(withCamera: true);

    visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Haz clic para subir')
        ->assertScript(
            'document.querySelectorAll("input[type=file]:not([capture])").length',
            1,
        )
        ->assertScript(
            'document.querySelector("input[type=file]:not([capture])").accept',
            '',
        );
})->group('browser');

it('is an ordinary picker when the manifest does not ask for the camera', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = cameraFieldApp(withCamera: false);

    visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertDontSee('Tomar una foto')
        ->assertScript('document.querySelectorAll("input[type=file][capture]").length', 0);
})->group('browser');
