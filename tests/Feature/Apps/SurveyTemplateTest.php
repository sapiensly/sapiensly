<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\AppPackage;
use App\Services\Apps\AppTemplateCatalog;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\FormParticipation;
use App\Services\Records\RecordQueryService;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The survey template, installed and then USED.
 *
 * Validating the manifest only proves it parses. What matters is that the
 * wiring holds when somebody actually answers: the questionnaire is picked off
 * the URL, the submission carries no name, the marker does, and a second
 * filing is refused. Every one of those crosses the template, the schema and
 * the controller at once — which is exactly where a hand-authored manifest goes
 * wrong.
 */
function installSurveyTemplate(): array
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $owner = mcpMember(mcpOrg());
    test()->actingAs($owner);

    $package = app(AppTemplateCatalog::class)->package('encuestas');
    expect($package)->not->toBeNull();

    $result = app(AppPackage::class)->import($package, $owner);
    $app = $result['app'] instanceof App ? $result['app'] : App::find($result['app']);

    return [$app->fresh(), app(AppManifestService::class)->getActiveManifest($app->fresh()), $owner];
}

/**
 * Ids move on import, so nothing here may hardcode one — the same reason the
 * runtime addresses everything by id and never by position.
 *
 * @param  array<string, mixed>  $manifest
 */
function surveyIds(array $manifest): array
{
    $bySlug = collect($manifest['objects'])->keyBy('slug');

    $form = collect($manifest['pages'])
        ->firstWhere('slug', 'contestar')['blocks'];

    return [
        'encuestas' => $bySlug['encuestas']['id'],
        'preguntas' => $bySlug['preguntas']['id'],
        'envios' => $bySlug['envios']['id'],
        'respuestas' => $bySlug['respuestas']['id'],
        'participacion' => $bySlug['participacion']['id'],
        'personas' => $bySlug['personas']['id'],
        'block' => collect($form)->firstWhere('type', 'record_form')['id'],
    ];
}

/** A roster row for somebody, so the questionnaire is actually sent to them. */
function rosterRow(App $app, array $ids, User $person, string $name = 'Ana'): Record
{
    return Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['personas'],
        'data' => ['nombre' => $name, 'usuario' => (string) $person->id],
    ]);
}

it('installs and carries every piece the questionnaire needs', function () {
    [, $manifest] = installSurveyTemplate();
    $ids = surveyIds($manifest);

    $block = collect($manifest['pages'])->firstWhere('slug', 'contestar')['blocks'];
    $form = collect($block)->firstWhere('type', 'record_form');

    expect(collect($manifest['objects'])->pluck('slug')->all())
        ->toBe(['encuestas', 'preguntas', 'envios', 'respuestas', 'participacion', 'personas'])
        // The pair the whole design rests on: anonymous submission, separate marker.
        ->and($form['submission']['anonymous'])->toBeTrue()
        ->and($form['participation']['object_id'])->toBe($ids['participacion'])
        // Every kind the template offers has somewhere to land — a kind with
        // no column is silently dropped at file time. Compared as SETS: the
        // order of a map's keys carries no meaning and the installer does not
        // promise to preserve it.
        ->and(collect($form['type_map'])->keys()->sort()->values()->all())
        ->toBe(['escala_1_5', 'opcion_unica', 'si_no', 'texto_libre'])
        ->and(collect($form['type_map'])->values()->sort()->values()->all())
        ->toBe(['boolean', 'long_text', 'rating', 'single_select'])
        // …and each of those maps onto a real column on the answers object.
        ->and(collect($form['answers']['value_field_ids'])->keys()->sort()->values()->all())
        ->toBe(['boolean', 'long_text', 'rating', 'single_select']);
});

it('files a real answer, keeps it anonymous, and refuses a second one', function () {
    [$app, $manifest, $owner] = installSurveyTemplate();
    $ids = surveyIds($manifest);

    $survey = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['encuestas'],
        'data' => ['nombre' => 'Clima 2026', 'activa' => true, 'anonima' => true],
    ]);

    $question = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['preguntas'],
        'data' => [
            'encuesta' => $survey->id,
            'texto' => '¿Recomendarías la empresa?',
            'tipo' => 'escala_1_5',
            'obligatoria' => true,
            'orden' => 1,
        ],
    ]);

    $roster = rosterRow($app, $ids, $owner);

    // ?encuesta is how one app holds many questionnaires. It reaches the
    // submission and the marker as an expression, so if it were written
    // literally this would fail on the relation validation.
    $post = fn () => $this->actingAs($owner)->postJson(
        "/r/{$app->slug}/forms/{$ids['block']}/submit?encuesta={$survey->id}",
        ['answers' => [['question_id' => $question->id, 'kind' => 'rating', 'value' => 5]]],
    );

    $post()->assertOk()->assertJson(['answers' => 1, 'anonymous' => true]);

    $submission = Record::where('object_definition_id', $ids['envios'])->first();
    $answer = Record::where('object_definition_id', $ids['respuestas'])->first();
    $marker = Record::where('object_definition_id', $ids['participacion'])->first();

    expect($submission->data['encuesta'])->toBe($survey->id)
        // Coarsened: an exact time re-identifies as surely as a name.
        ->and($submission->data['completado_en'])->toBe(now()->toDateString())
        ->and($submission->created_by_user_id)->toBeNull()
        ->and($answer->data['escala'])->toBe(5)
        ->and($answer->created_by_user_id)->toBeNull()
        // …and the marker names the person, pointing at nothing they said.
        ->and($marker->data['persona'])->toBe($roster->id)
        ->and($marker->data['encuesta'])->toBe($survey->id);

    $post()->assertStatus(409);

    expect(Record::where('object_definition_id', $ids['envios'])->count())->toBe(1);
});

it('does not let answering one questionnaire count as answering another', function () {
    // The scoping the template depends on. Both surveys share ONE participation
    // object, so without {{params.encuesta}} on the marker the first filing
    // would mark somebody as done with every survey in the app.
    [$app, $manifest, $owner] = installSurveyTemplate();
    $ids = surveyIds($manifest);

    $surveys = collect(['Clima', 'Onboarding'])->map(fn (string $name): Record => Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['encuestas'],
        'data' => ['nombre' => $name, 'activa' => true, 'anonima' => true],
    ]));

    $questions = $surveys->map(fn (Record $s): Record => Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['preguntas'],
        'data' => ['encuesta' => $s->id, 'texto' => '¿Qué tal?', 'tipo' => 'escala_1_5', 'orden' => 1],
    ]));

    rosterRow($app, $ids, $owner);

    foreach ([0, 1] as $i) {
        $this->actingAs($owner)->postJson(
            "/r/{$app->slug}/forms/{$ids['block']}/submit?encuesta={$surveys[$i]->id}",
            ['answers' => [['question_id' => $questions[$i]->id, 'kind' => 'rating', 'value' => 4]]],
        )->assertOk();
    }

    expect(Record::where('object_definition_id', $ids['envios'])->count())->toBe(2);
});

it('shows the form as already answered on the way in, not on the way out', function () {
    // The runtime asks the same question the controller does, so a person who
    // already filed sees "you already answered" instead of a form whose submit
    // is going to be refused.
    [$app, $manifest, $owner] = installSurveyTemplate();
    $ids = surveyIds($manifest);

    $survey = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['encuestas'],
        'data' => ['nombre' => 'Clima', 'activa' => true, 'anonima' => true],
    ]);

    $block = collect(collect($manifest['pages'])->firstWhere('slug', 'contestar')['blocks'])
        ->firstWhere('type', 'record_form');

    $roster = rosterRow($app, $ids, $owner);
    $context = ['params' => ['encuesta' => $survey->id]];
    $participation = app(FormParticipation::class);

    expect($participation->hasAnswered($app, $block, $manifest, $owner, $context))->toBeFalse();

    Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['participacion'],
        'data' => ['persona' => $roster->id, 'encuesta' => $survey->id],
    ]);

    expect($participation->hasAnswered($app, $block, $manifest, $owner, $context))->toBeTrue()
        // …and still open for a survey they have not touched.
        ->and($participation->hasAnswered($app, $block, $manifest, $owner, ['params' => ['encuesta' => 'rec_otra']]))
        ->toBeFalse();
});

it('shrinks the list of who still owes a response as they answer', function () {
    // The half the marker was always for. This list is readable without a
    // single answer being readable — which is the entire reason the marker is
    // a separate record that points at nothing anybody said.
    [$app, $manifest, $owner] = installSurveyTemplate();
    $ids = surveyIds($manifest);

    $survey = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['encuestas'],
        'data' => ['nombre' => 'Clima', 'activa' => true, 'anonima' => true],
    ]);

    $question = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['preguntas'],
        'data' => ['encuesta' => $survey->id, 'texto' => '¿Qué tal?', 'tipo' => 'escala_1_5', 'orden' => 1],
    ]);

    // Two people on the roster; one of them is the person about to answer.
    foreach ([['Ana', (string) $owner->id], ['Beto', '999999']] as [$nombre, $usuario]) {
        Record::create([
            'app_id' => $app->id,
            'organization_id' => $app->organization_id,
            'object_definition_id' => $ids['personas'],
            'data' => ['nombre' => $nombre, 'usuario' => $usuario],
        ]);
    }

    $pending = function () use ($app, $manifest, $survey): array {
        $page = collect($manifest['pages'])->firstWhere('slug', 'pendientes');
        $block = collect($page['blocks'])->firstWhere('type', 'table');

        return app(RecordQueryService::class)
            ->query($app, $block['data_source'], $manifest, ['params' => ['encuesta' => $survey->id]])
            ->pluck('data.nombre')
            ->all();
    };

    expect($pending())->toBe(['Ana', 'Beto']);

    $this->actingAs($owner)->postJson(
        "/r/{$app->slug}/forms/{$ids['block']}/submit?encuesta={$survey->id}",
        ['answers' => [['question_id' => $question->id, 'kind' => 'rating', 'value' => 3]]],
    )->assertOk();

    // Ana answered; the marker points at her ROSTER row, not at her answers.
    expect($pending())->toBe(['Beto']);

    $marker = Record::where('object_definition_id', $ids['participacion'])->first();
    $ana = Record::where('object_definition_id', $ids['personas'])->where('data->nombre', 'Ana')->first();

    expect($marker->data['persona'])->toBe($ana->id);
});

it('refuses somebody who is not on the roster, before writing anything', function () {
    // Filing the answers and quietly skipping the marker would be worse than
    // refusing: they could answer again tomorrow, and they would never appear
    // on the list of who still owes a response.
    [$app, $manifest, $owner] = installSurveyTemplate();
    $ids = surveyIds($manifest);

    $survey = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['encuestas'],
        'data' => ['nombre' => 'Clima', 'activa' => true, 'anonima' => true],
    ]);

    $question = Record::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'object_definition_id' => $ids['preguntas'],
        'data' => ['encuesta' => $survey->id, 'texto' => '¿Qué tal?', 'tipo' => 'escala_1_5', 'orden' => 1],
    ]);

    // Nobody on the roster at all.
    $this->actingAs($owner)->postJson(
        "/r/{$app->slug}/forms/{$ids['block']}/submit?encuesta={$survey->id}",
        ['answers' => [['question_id' => $question->id, 'kind' => 'rating', 'value' => 3]]],
    )->assertStatus(403)->assertJson(['error' => 'not_invited']);

    expect(Record::where('object_definition_id', $ids['envios'])->count())->toBe(0)
        ->and(Record::where('object_definition_id', $ids['respuestas'])->count())->toBe(0);
});
