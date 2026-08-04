<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A form drawn from records, in a browser.
 *
 * This is the half the server cannot show. The whole point of the block is that
 * a scale question becomes a REAL rating control and a free-text question a
 * real textarea — rather than a writable grid with one column per possible
 * answer type, where the person answering has to work out which column their
 * question belongs to. That difference only exists on screen.
 */
function recordFormApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'rfm_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Clima',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [
            [
                'id' => 'obj_pregunta001',
                'slug' => 'preguntas',
                'name' => 'Preguntas',
                'fields' => [
                    ['id' => 'fld_texto000001', 'slug' => 'texto', 'name' => 'Texto', 'type' => 'string'],
                    [
                        'id' => 'fld_tipo000001', 'slug' => 'tipo', 'name' => 'Tipo',
                        'type' => 'single_select',
                        'options' => [
                            ['id' => 'opt_escala00001', 'value' => 'escala_1_5', 'label' => 'Escala'],
                            ['id' => 'opt_sino000001', 'value' => 'si_no', 'label' => 'Sí/No'],
                            ['id' => 'opt_texto000001', 'value' => 'texto_libre', 'label' => 'Texto'],
                        ],
                    ],
                    ['id' => 'fld_oblig000001', 'slug' => 'obligatoria', 'name' => 'Obligatoria', 'type' => 'boolean'],
                ],
            ],
            [
                'id' => 'obj_respuesta01',
                'slug' => 'respuestas',
                'name' => 'Respuestas',
                'fields' => [
                    ['id' => 'fld_num00000001', 'slug' => 'numero', 'name' => 'Número', 'type' => 'number'],
                    ['id' => 'fld_bool00000001', 'slug' => 'booleano', 'name' => 'Booleano', 'type' => 'boolean'],
                    ['id' => 'fld_libre000001', 'slug' => 'libre', 'name' => 'Libre', 'type' => 'long_text'],
                    ['id' => 'fld_preg000001', 'slug' => 'pregunta', 'name' => 'Pregunta', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_pregunta001'],
                ],
            ],
            [
                'id' => 'obj_particip001',
                'slug' => 'participacion',
                'name' => 'Participación',
                'fields' => [
                    ['id' => 'fld_persona0001', 'slug' => 'persona', 'name' => 'Persona', 'type' => 'string'],
                ],
            ],
        ],
        'pages' => [[
            'id' => 'pag_contestar01',
            'slug' => 'contestar',
            'path' => '/contestar',
            'name' => 'Contestar',
            'blocks' => [[
                'id' => 'blk_encuesta001',
                'type' => 'record_form',
                'questions' => ['object_id' => 'obj_pregunta001'],
                'label_field_id' => 'fld_texto000001',
                'type_field_id' => 'fld_tipo000001',
                'type_map' => [
                    'escala_1_5' => 'rating',
                    'si_no' => 'boolean',
                    'texto_libre' => 'long_text',
                ],
                'required_field_id' => 'fld_oblig000001',
                'answers' => [
                    'object_id' => 'obj_respuesta01',
                    'question_field_id' => 'fld_preg000001',
                    'value_field_ids' => [
                        'rating' => 'fld_num00000001',
                        'boolean' => 'fld_bool00000001',
                        'long_text' => 'fld_libre000001',
                    ],
                ],
                // Who answered, in a record pointing at nothing they said —
                // and the only thing that makes "already filed" enforceable.
                'participation' => [
                    'object_id' => 'obj_particip001',
                    'person_field_id' => 'fld_persona0001',
                ],
                'submit_label' => 'Enviar',
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    foreach ([
        ['¿Recomendarías esta empresa?', 'escala_1_5', true],
        ['¿Tienes las herramientas que necesitas?', 'si_no', false],
        ['¿Qué cambiarías?', 'texto_libre', false],
    ] as [$text, $tipo, $required]) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => 'obj_pregunta001',
            'data' => ['texto' => $text, 'tipo' => $tipo, 'obligatoria' => $required],
        ]);
    }

    return $app;
}

it('draws one input per question, each of its own kind', function () {
    // The entire reason this block exists. A grid would show three columns and
    // make the person work out which one their question belongs to.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = recordFormApp();

    visit("/r/{$app->slug}/contestar")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // The questions are the labels, in the order they were authored.
        ->assertSee('¿Recomendarías esta empresa?')
        ->assertSee('¿Qué cambiarías?')
        ->assertScript('document.querySelectorAll("[data-sp-question]").length', 3)
        // A scale is a real rating control, not a number box.
        ->assertScript(
            'document.querySelectorAll("[data-sp-question] button[title]").length',
            5,
        )
        // …and free text is a real textarea.
        ->assertScript('document.querySelectorAll("[data-sp-question] textarea").length', 1);
})->group('browser');

it('marks the required ones, and refuses to send while one is empty', function () {
    // Named rather than counted: "something was missing" makes somebody read
    // the whole form again.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = recordFormApp();

    $page = visit("/r/{$app->slug}/contestar")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->click('Enviar');

    $page->assertSee('Faltan preguntas obligatorias')
        ->assertSee('Esta es obligatoria')
        ->assertNoJavaScriptErrors();

    expect(Record::where('object_definition_id', 'obj_respuesta01')->count())->toBe(0);
})->group('browser');

it('files the answers and then stops offering to file them again', function () {
    // Terminal on purpose: re-submitting is how somebody answers twice, and on
    // an anonymous survey there is no way afterwards to tell which was theirs.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = recordFormApp();

    $page = visit("/r/{$app->slug}/contestar")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Answer the required scale, and the free text.
    $page->script(<<<'JS'
        (() => {
            const stars = document.querySelectorAll('[data-sp-question] button[title]');
            stars[3].click();
            const area = document.querySelector('[data-sp-question] textarea');
            area.value = 'Menos reuniones';
            area.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
        })()
    JS);

    $page->click('Enviar');

    $page->assertSee('Gracias')
        ->assertNoJavaScriptErrors()
        ->assertScript('!!document.querySelector("[data-sp-form-submit]")', false);

    $answers = Record::where('object_definition_id', 'obj_respuesta01')->get();

    expect($answers)->toHaveCount(2)
        // Each landed in the column its kind belongs to.
        ->and($answers->pluck('data.numero')->filter()->values()->all())->toBe([4])
        ->and($answers->pluck('data.libre')->filter()->values()->all())->toBe(['Menos reuniones']);
})->group('browser');

it('does not offer the form again after a reload', function () {
    // The hole a client-side flag cannot cover. The block hid itself on send,
    // but that lived in the browser: reloading brought the whole form back, and
    // on an anonymous questionnaire nothing afterwards can tell a duplicate
    // filing from the original — so it cannot even be cleaned up later.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = recordFormApp();

    $page = visit("/r/{$app->slug}/contestar")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            document.querySelectorAll('[data-sp-question] button[title]')[2].click();
            return true;
        })()
    JS);

    $page->click('Enviar')->assertSee('Gracias');

    $filed = Record::where('object_definition_id', 'obj_respuesta01')->count();

    visit("/r/{$app->slug}/contestar")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Ya contestaste esto')
        ->assertScript('!!document.querySelector("[data-sp-form-submit]")', false);

    expect(Record::where('object_definition_id', 'obj_respuesta01')->count())->toBe($filed);
})->group('browser');
