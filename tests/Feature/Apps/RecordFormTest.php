<?php

use App\Enums\MembershipRole;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Filing a questionnaire somebody else authored.
 *
 * The block that lets an app's own users define a form: HR writes the questions
 * as data and an employee answers a real form rather than a spreadsheet with
 * one column per possible answer type.
 *
 * Two things are worth testing hard. One submission and N answers land in ONE
 * transaction, because half a survey is not a smaller survey — it is a response
 * that gets counted and is wrong, and afterwards a skipped question and a
 * failed write look identical in every chart. And ANONYMITY actually holds:
 * nothing on the submission or its answers points at the person, including the
 * things a platform writes without being asked.
 */
function formApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'frm_'.Str::lower(Str::random(6)),
        'name' => 'Encuestas',
    ]);

    $manifest = array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Encuestas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [
            [
                'id' => 'obj_pregunta001',
                'name' => 'Preguntas',
                'slug' => 'preguntas',
                'fields' => [
                    ['id' => 'fld_texto000001', 'name' => 'Texto', 'slug' => 'texto', 'type' => 'string'],
                    [
                        'id' => 'fld_tipo000001', 'name' => 'Tipo', 'slug' => 'tipo',
                        'type' => 'single_select',
                        'options' => [
                            ['id' => 'opt_escala00001', 'value' => 'escala_1_5', 'label' => 'Escala'],
                            ['id' => 'opt_texto000001', 'value' => 'texto_libre', 'label' => 'Texto'],
                        ],
                    ],
                    ['id' => 'fld_oblig000001', 'name' => 'Obligatoria', 'slug' => 'obligatoria', 'type' => 'boolean'],
                ],
            ],
            [
                'id' => 'obj_respuesta01',
                'name' => 'Respuestas',
                'slug' => 'respuestas',
                'fields' => [
                    ['id' => 'fld_num00000001', 'name' => 'Número', 'slug' => 'numero', 'type' => 'number'],
                    ['id' => 'fld_libre000001', 'name' => 'Libre', 'slug' => 'libre', 'type' => 'long_text'],
                    ['id' => 'fld_preg000001', 'name' => 'Pregunta', 'slug' => 'pregunta', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_pregunta001'],
                    ['id' => 'fld_envio000001', 'name' => 'Envío', 'slug' => 'envio', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_envio000001'],
                ],
            ],
            [
                'id' => 'obj_envio000001',
                'name' => 'Envíos',
                'slug' => 'envios',
                'fields' => [
                    ['id' => 'fld_compl000001', 'name' => 'Completado en', 'slug' => 'completado_en', 'type' => 'string'],
                ],
            ],
            [
                'id' => 'obj_particip001',
                'name' => 'Participación',
                'slug' => 'participacion',
                'fields' => [
                    ['id' => 'fld_persona0001', 'name' => 'Persona', 'slug' => 'persona', 'type' => 'string'],
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
                'type_map' => ['escala_1_5' => 'rating', 'texto_libre' => 'long_text'],
                'required_field_id' => 'fld_oblig000001',
                'answers' => [
                    'object_id' => 'obj_respuesta01',
                    // Addressed by field ID like everything else in a manifest;
                    // the write path accepts either, but ids are what survives
                    // somebody renaming a slug.
                    'question_field_id' => 'fld_preg000001',
                    'parent_field_id' => 'fld_envio000001',
                    'value_field_ids' => ['rating' => 'fld_num00000001', 'long_text' => 'fld_libre000001'],
                ],
                'submission' => ['object_id' => 'obj_envio000001', 'anonymous' => true],
                'participation' => ['object_id' => 'obj_particip001', 'person_field_id' => 'fld_persona0001'],
                'submit_label' => 'Enviar',
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $overrides);

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return $app->fresh();
}

function question(App $app, string $text, string $tipo, bool $required = false): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_pregunta001',
        'organization_id' => $app->organization_id,
        'data' => ['texto' => $text, 'tipo' => $tipo, 'obligatoria' => $required],
    ]);
}

it('files one submission and every answer with it', function () {
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q1 = question($app, '¿Recomendarías la empresa?', 'escala_1_5');
    $q2 = question($app, '¿Qué cambiarías?', 'texto_libre');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [
                ['question_id' => $q1->id, 'kind' => 'rating', 'value' => 4],
                ['question_id' => $q2->id, 'kind' => 'long_text', 'value' => 'Menos reuniones'],
            ],
        ])
        ->assertOk()
        ->assertJson(['answers' => 2, 'anonymous' => true]);

    $answers = Record::where('object_definition_id', 'obj_respuesta01')->get();
    $submission = Record::where('object_definition_id', 'obj_envio000001')->first();

    expect($answers)->toHaveCount(1 + 1)
        ->and($submission)->not->toBeNull()
        // Every answer is grouped by the one submission.
        ->and($answers->pluck('data.envio')->unique()->all())->toBe([$submission->id]);
});

it('writes each answer into the column its kind belongs in', function () {
    // Separate columns rather than one, because a number stored as text cannot
    // be averaged — and the average per question is what HR bought this for.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q1 = question($app, 'Escala', 'escala_1_5');
    $q2 = question($app, 'Texto', 'texto_libre');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
        'answers' => [
            ['question_id' => $q1->id, 'kind' => 'rating', 'value' => 5],
            ['question_id' => $q2->id, 'kind' => 'long_text', 'value' => 'algo'],
        ],
    ])->assertOk();

    $byQuestion = Record::where('object_definition_id', 'obj_respuesta01')
        ->get()
        ->keyBy(fn (Record $r): string => (string) $r->data['pregunta']);

    expect($byQuestion[$q1->id]->data['numero'])->toBe(5)
        ->and($byQuestion[$q1->id]->data['libre'] ?? null)->toBeNull()
        ->and($byQuestion[$q2->id]->data['libre'])->toBe('algo');
});

it('leaves NOTHING on an anonymous submission that points at the person', function () {
    // The assertion the whole feature rests on. The moment employees suspect a
    // climate survey is traceable, the answers stop meaning anything — and the
    // platform stamps created_by_user_id without being asked.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
        'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 2]],
    ])->assertOk();

    $submission = Record::where('object_definition_id', 'obj_envio000001')->first();
    $answer = Record::where('object_definition_id', 'obj_respuesta01')->first();

    // Compared as VALUES, not as a substring of the encoded row. A user id is a
    // small integer and a record id is a ULID that happens to contain digits,
    // so a substring search decides this by coincidence — it passed for a while
    // only because no generated id had happened to contain the right one yet.
    $carries = static fn (array $data): bool => in_array(
        (string) $owner->id,
        array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $data),
        true,
    );

    expect($submission->created_by_user_id)->toBeNull()
        ->and($answer->created_by_user_id)->toBeNull()
        ->and($carries($submission->data))->toBeFalse()
        ->and($carries($answer->data))->toBeFalse();
});

it('coarsens an anonymous timestamp to the date', function () {
    // An exact time re-identifies as surely as a name: thirty people produce
    // thirty distinct minutes, and the participation marker is timestamped too.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
        'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 2]],
    ])->assertOk();

    $submission = Record::where('object_definition_id', 'obj_envio000001')->first();

    expect($submission->data['completado_en'])->toBe(now()->toDateString())
        ->and($submission->data['completado_en'])->not->toContain(':');
});

it('still records that the person answered, in a record pointing at nothing they said', function () {
    // The half that lets somebody be reminded without being read.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
        'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 2]],
    ])->assertOk();

    $marker = Record::where('object_definition_id', 'obj_particip001')->first();
    $submission = Record::where('object_definition_id', 'obj_envio000001')->first();

    expect($marker)->not->toBeNull()
        ->and($marker->data['persona'])->toBe((string) $owner->id)
        // And nothing joins the two.
        ->and(json_encode($marker->data))->not->toContain($submission->id);
});

it('keeps the person on a submission that is not anonymous', function () {
    $owner = User::factory()->create();
    $app = formApp($owner, ['pages' => [[
        'blocks' => [['submission' => ['object_id' => 'obj_envio000001', 'anonymous' => false]]],
    ]]]);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
        'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 2]],
    ])->assertOk()->assertJson(['anonymous' => false]);

    expect(Record::where('object_definition_id', 'obj_envio000001')->first()->created_by_user_id)
        ->toBe($owner->id);
});

it('writes nothing at all when one answer fails', function () {
    // Half a survey is not a smaller survey: it gets counted and it is wrong,
    // and afterwards a skipped question and a failed write look identical.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [
                ['question_id' => $q->id, 'kind' => 'rating', 'value' => 3],
                // A rating is a number; this is not, and the write path refuses it.
                ['question_id' => $q->id, 'kind' => 'rating', 'value' => 'no soy un número'],
            ],
        ])
        ->assertStatus(500);

    expect(Record::where('object_definition_id', 'obj_respuesta01')->count())->toBe(0)
        ->and(Record::where('object_definition_id', 'obj_envio000001')->count())->toBe(0);
});

it('takes the object to write to from the MANIFEST, never from the browser', function () {
    // The block is the contract. A caller naming its own object would be
    // choosing where to write, which is the whole game.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_noexiste001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 1]],
        ])
        ->assertNotFound();
});

it('is refused to a role that may not file an answer', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $owner = mcpMember($org);
    $app = formApp($owner, ['permissions' => [
        'roles' => [['id' => 'rol_lector0001', 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true]],
        'object_policies' => [[
            'object_id' => 'obj_respuesta01',
            'role_id' => 'rol_lector0001',
            'actions' => ['read'],
        ]],
    ]]);
    $app->update(['visibility' => 'organization']);

    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs(mcpMember($org, MembershipRole::Member))
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 1]],
        ])
        ->assertForbidden();
});

it('bounds how many answers one submission may carry', function () {
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => array_fill(0, 201, ['question_id' => $q->id, 'kind' => 'rating', 'value' => 1]),
        ])
        ->assertStatus(422);
});
