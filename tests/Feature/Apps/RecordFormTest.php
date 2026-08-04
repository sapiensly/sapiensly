<?php

use App\Enums\MembershipRole;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\FormParticipation;
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
                'submission' => ['object_id' => 'obj_envio000001', 'anonymous' => true, 'completed_field_id' => 'fld_compl000001'],
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

it('refuses a second filing from the same person', function () {
    // The hole this closes. The block hid itself after sending, but that lived
    // in the browser: a reload brought the whole form back. On an ANONYMOUS
    // questionnaire a duplicate is not merely untidy — nothing afterwards can
    // tell which of the two filings was theirs, so it cannot even be cleaned up.
    $owner = User::factory()->create();
    $app = formApp($owner);
    $q = question($app, 'Escala', 'escala_1_5');

    $submit = fn () => $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 3]],
        ]);

    $submit()->assertOk();
    $submit()->assertStatus(409)->assertJson(['error' => 'already_answered']);

    expect(Record::where('object_definition_id', 'obj_envio000001')->count())->toBe(1)
        ->and(Record::where('object_definition_id', 'obj_respuesta01')->count())->toBe(1)
        ->and(Record::where('object_definition_id', 'obj_particip001')->count())->toBe(1);
});

it('lets a questionnaire be filed repeatedly when the author asks for it', function () {
    // A daily checklist and a shift inspection are the same block, and they are
    // MEANT to be filed again tomorrow. One-per-person is the safe default, not
    // a law: the loud failure (a checklist that refuses today) beats the silent
    // one (a survey quietly counting duplicates).
    $owner = User::factory()->create();
    $app = formApp($owner, ['pages' => [['blocks' => [[
        'participation' => [
            'object_id' => 'obj_particip001',
            'person_field_id' => 'fld_persona0001',
            'once' => false,
        ],
    ]]]]]);
    $q = question($app, 'Escala', 'escala_1_5');

    foreach ([1, 2] as $round) {
        $this->actingAs($owner)
            ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
                'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => $round]],
            ])
            ->assertOk();
    }

    expect(Record::where('object_definition_id', 'obj_envio000001')->count())->toBe(2);
});

it('does not let one person answering block anybody else', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $owner = mcpMember($org);
    $other = mcpMember($org);
    $app = formApp($owner);
    // Personal by default; two people can only share a questionnaire that
    // belongs to the organization.
    $app->update(['visibility' => 'organization']);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 1]],
        ])->assertOk();

    $this->actingAs($other)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 5]],
        ])->assertOk();

    expect(Record::where('object_definition_id', 'obj_envio000001')->count())->toBe(2);
});

it('does not make answering one questionnaire count as answering another', function () {
    // One participation object usually serves every questionnaire in an app.
    // Without scoping by what the marker CARRIES, filing the climate survey
    // would silently mark somebody as having done the onboarding one too.
    $owner = User::factory()->create();
    $app = formApp($owner, [
        'objects' => [3 => ['fields' => [1 => [
            'id' => 'fld_cual00000001', 'name' => 'Cuál', 'slug' => 'cual', 'type' => 'string',
        ]]]],
        'pages' => [['blocks' => [
            0 => ['participation' => [
                'object_id' => 'obj_particip001',
                'person_field_id' => 'fld_persona0001',
                'values' => ['fld_cual00000001' => 'clima'],
            ]],
        ]]],
    ]);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 3]],
        ])->assertOk();

    $marker = Record::where('object_definition_id', 'obj_particip001')->first();

    // The marker says WHICH questionnaire, so the same person is still owed by
    // any other one keyed differently.
    expect($marker->data['cual'])->toBe('clima')
        ->and(app(FormParticipation::class)->hasAnswered(
            $app->fresh(),
            ['participation' => [
                'object_id' => 'obj_particip001',
                'person_field_id' => 'fld_persona0001',
                'values' => ['fld_cual00000001' => 'onboarding'],
            ]],
            app(AppManifestService::class)->getActiveManifest($app->fresh()),
            $owner,
        ))->toBeFalse();
});

it('leaves a questionnaire with no marker open, because nothing can key it', function () {
    // A suggestion box: anonymous, no marker, unlimited filings. Undedupable by
    // construction rather than by oversight — the marker is the ONLY thing an
    // anonymous submission leaves behind that names anybody.
    $owner = User::factory()->create();
    $app = formApp($owner);

    $manifest = app(AppManifestService::class)->getActiveManifest($app);
    unset($manifest['pages'][0]['blocks'][0]['participation']);
    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    $q = question($app, 'Escala', 'escala_1_5');

    foreach ([1, 2] as $round) {
        $this->actingAs($owner)
            ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
                'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => $round]],
            ])->assertOk();
    }

    expect(Record::where('object_definition_id', 'obj_envio000001')->count())->toBe(2)
        ->and(Record::where('object_definition_id', 'obj_particip001')->count())->toBe(0);
});

it('stamps the field the author named, whatever it is called', function () {
    // The regression this guards. The stamp used to go into a hardcoded
    // `completado_en`, and RecordWriteService builds its row from the object's
    // DECLARED fields — so for every app that did not happen to name the field
    // in Spanish the timestamp was dropped without a word.
    $owner = User::factory()->create();
    $app = formApp($owner, ['objects' => [2 => ['fields' => [0 => [
        'id' => 'fld_compl000001', 'name' => 'Filed at', 'slug' => 'filed_at', 'type' => 'string',
    ]]]]]);
    $q = question($app, 'Escala', 'escala_1_5');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/forms/blk_encuesta001/submit", [
            'answers' => [['question_id' => $q->id, 'kind' => 'rating', 'value' => 2]],
        ])->assertOk();

    $submission = Record::where('object_definition_id', 'obj_envio000001')->first();

    expect($submission->data['filed_at'])->toBe(now()->toDateString());
});

/**
 * The block on its own, against the validator.
 *
 * Not through formApp: its overrides merge recursively, so "remove this entry"
 * is not expressible there — the maps came back with the valid entries intact
 * and nothing to reject.
 */
function validateRecordForm(array $typeMap, array $columns): array
{
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_0000000000000001',
        'slug' => 'enc',
        'name' => 'Encuestas',
        'version' => 1,
        'objects' => [
            [
                'id' => 'obj_pregunta001', 'slug' => 'preguntas', 'name' => 'Preguntas',
                'fields' => [
                    ['id' => 'fld_texto000001', 'slug' => 'texto', 'name' => 'Texto', 'type' => 'string', 'required' => true],
                    [
                        'id' => 'fld_tipo000001', 'slug' => 'tipo', 'name' => 'Tipo', 'type' => 'single_select',
                        'options' => [
                            ['id' => 'opt_escala00001', 'value' => 'escala_1_5', 'label' => 'Escala 1-5'],
                            ['id' => 'opt_texto000001', 'value' => 'texto_libre', 'label' => 'Texto libre'],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'obj_respuesta01', 'slug' => 'respuestas', 'name' => 'Respuestas',
                'fields' => [
                    ['id' => 'fld_num00000001', 'slug' => 'numero', 'name' => 'Número', 'type' => 'number'],
                    ['id' => 'fld_libre000001', 'slug' => 'libre', 'name' => 'Libre', 'type' => 'long_text'],
                    ['id' => 'fld_preg000001', 'slug' => 'pregunta', 'name' => 'Pregunta', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_pregunta001'],
                ],
            ],
        ],
        'pages' => [[
            'id' => 'pag_contestar01', 'slug' => 'contestar', 'path' => '/contestar', 'name' => 'Contestar',
            'blocks' => [[
                'id' => 'blk_encuesta001',
                'type' => 'record_form',
                'questions' => ['object_id' => 'obj_pregunta001'],
                'label_field_id' => 'fld_texto000001',
                'type_field_id' => 'fld_tipo000001',
                'type_map' => $typeMap,
                'answers' => [
                    'object_id' => 'obj_respuesta01',
                    'question_field_id' => 'fld_preg000001',
                    'value_field_ids' => $columns,
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    return collect(app(ManifestValidator::class)->validate($manifest)->errors)
        ->map(fn ($e): string => $e->message)
        ->all();
}

it('rejects a type_map keyed by the label instead of the value', function () {
    // Taken from a live build. The model had already read the option values off
    // the manifest and printed them — then keyed the map by label anyway. Every
    // lookup missed, so every question, 1-5 scales included, rendered as plain
    // text while the build reported success.
    $errors = validateRecordForm(
        ['Escala 1-5' => 'rating', 'Texto libre' => 'long_text'],
        ['rating' => 'fld_num00000001', 'long_text' => 'fld_libre000001'],
    );

    expect(implode(' | ', $errors))
        ->toContain("type_map key 'Escala 1-5' is not one of the values")
        // …and it says what the values ARE, so the fix needs no second guess.
        ->toContain('escala_1_5');
});

it('rejects a kind that has nowhere to land', function () {
    // Silent at runtime: the answer is simply not written. Somebody fills in a
    // question and it lands nowhere, with nothing raising its hand.
    $errors = validateRecordForm(
        ['escala_1_5' => 'rating', 'texto_libre' => 'long_text'],
        ['rating' => 'fld_num00000001'],
    );

    expect(implode(' | ', $errors))->toContain("the kind 'long_text' but answers.value_field_ids has no column");
});

it('accepts the wiring when every key and kind lines up', function () {
    $errors = validateRecordForm(
        ['escala_1_5' => 'rating', 'texto_libre' => 'long_text'],
        ['rating' => 'fld_num00000001', 'long_text' => 'fld_libre000001'],
    );

    expect($errors)->toBe([]);
});
