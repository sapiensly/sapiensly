<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A published landing with a leads object, a lead_form block and a
 * record.created workflow — the full public conversion loop.
 */
function leadLanding(): App
{
    $user = User::factory()->create();
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'cap_'.strtolower(Str::random(6)),
        'name' => 'Captura',
        'public_slug' => 'cap_'.strtolower(Str::random(8)),
        'published_at' => now(),
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    $manifest['objects'] = [[
        'id' => 'obj_leadscap01', 'slug' => 'leads', 'name' => 'Leads',
        'fields' => [
            ['id' => 'fld_leadnom001', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ['id' => 'fld_leademl001', 'slug' => 'email', 'name' => 'Email', 'type' => 'string'],
            ['id' => 'fld_leadmsg001', 'slug' => 'mensaje', 'name' => 'Mensaje', 'type' => 'long_text'],
        ],
    ]];
    $manifest['pages'] = [[
        'id' => 'pag_capture001', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => 'htm_caphero01', 'type' => 'html', 'content' => '<section><h1>Hola</h1></section>'],
            ['id' => 'ldf_capture01', 'type' => 'lead_form', 'object_id' => 'obj_leadscap01',
                'fields' => [
                    ['field_id' => 'fld_leadnom001', 'label' => 'Nombre', 'required' => true],
                    ['field_id' => 'fld_leademl001', 'label' => 'Email', 'input' => 'email', 'required' => true],
                    ['field_id' => 'fld_leadmsg001', 'label' => 'Mensaje', 'input' => 'textarea'],
                ],
                'success_message' => 'Un agente ya está en ello.'],
        ],
    ]];
    $manifest['workflows'] = [[
        'id' => 'wfl_leadhand01', 'slug' => 'atender_lead', 'name' => 'Atender lead',
        'trigger' => ['type' => 'record.created', 'object_id' => 'obj_leadscap01'],
        'steps' => [['id' => 'stp_loglead01', 'type' => 'log', 'message' => 'Lead recibido']],
    ]];
    $manifests->createVersion($app, $manifest, $user, 'landing con captura');

    return $app->refresh();
}

it('creates the lead as a guest and fires the record.created workflow', function () {
    $app = leadLanding();

    $response = $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => [
            'fld_leadnom001' => 'Ana López',
            'fld_leademl001' => 'ana@example.com',
            'fld_leadmsg001' => 'Quiero un demo',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Un agente ya está en ello.');

    $lead = Record::query()
        ->where('app_id', $app->id)
        ->where('object_definition_id', 'obj_leadscap01')
        ->firstOrFail();
    expect($lead->data['nombre'])->toBe('Ana López')
        ->and($lead->data['email'])->toBe('ana@example.com')
        ->and($lead->data['mensaje'])->toBe('Quiero un demo');

    // The conversion loop: the insert fired the record.created workflow.
    expect(WorkflowRun::query()->where('app_id', $app->id)->count())->toBe(1);
});

it('ignores values for fields the block does not declare', function () {
    $app = leadLanding();

    $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => [
            'fld_leadnom001' => 'Ana',
            'fld_leademl001' => 'ana@example.com',
            // Not declared on the block — must never be written.
            'fld_forged0001' => 'hax',
            'estado' => 'ganado',
        ],
    ])->assertOk();

    $lead = Record::query()->where('app_id', $app->id)->firstOrFail();
    expect($lead->data)->not->toHaveKey('estado')
        ->and(array_keys($lead->data))->not->toContain('fld_forged0001');
});

it('422s when a required field is missing', function () {
    $app = leadLanding();

    $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => ['fld_leadnom001' => 'Ana'], // email missing
    ])->assertStatus(422)->assertJsonPath('ok', false);

    expect(Record::query()->where('app_id', $app->id)->count())->toBe(0);
});

it('fakes success and writes nothing when the honeypot is filled', function () {
    $app = leadLanding();

    $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => ['fld_leadnom001' => 'Bot', 'fld_leademl001' => 'bot@spam.com'],
        'website' => 'https://spam.example',
    ])->assertOk()->assertJsonPath('ok', true);

    expect(Record::query()->where('app_id', $app->id)->count())->toBe(0)
        ->and(WorkflowRun::query()->where('app_id', $app->id)->count())->toBe(0);
});

it('verifies Turnstile when configured and rejects a bad token', function () {
    config(['services.turnstile.secret_key' => 'secret-x']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
    $app = leadLanding();

    $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => ['fld_leadnom001' => 'Ana', 'fld_leademl001' => 'ana@example.com'],
        'turnstile_token' => 'bad-token',
    ])->assertStatus(422);

    expect(Record::query()->where('app_id', $app->id)->count())->toBe(0);
});

it('accepts a valid Turnstile token', function () {
    config(['services.turnstile.secret_key' => 'secret-x']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    $app = leadLanding();

    $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => ['fld_leadnom001' => 'Ana', 'fld_leademl001' => 'ana@example.com'],
        'turnstile_token' => 'good-token',
    ])->assertOk();

    expect(Record::query()->where('app_id', $app->id)->count())->toBe(1);
});

it('404s an unknown block id and an unpublished landing', function () {
    $app = leadLanding();

    $this->postJson("/l/{$app->public_slug}/lead", [
        'block_id' => 'ldf_nope00001',
        'values' => ['fld_leadnom001' => 'Ana'],
    ])->assertNotFound();

    $slug = $app->public_slug;
    $app->forceFill(['published_at' => null])->save();
    $this->postJson("/l/{$slug}/lead", [
        'block_id' => 'ldf_capture01',
        'values' => ['fld_leadnom001' => 'Ana', 'fld_leademl001' => 'a@b.com'],
    ])->assertNotFound();
});
