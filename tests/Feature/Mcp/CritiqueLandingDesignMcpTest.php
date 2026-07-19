<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\CritiqueLandingDesignTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

function mcpCritiqueLanding(User $user, string $html, string $css): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'critique_me',
        'name' => 'Critique Me',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing', 'custom_css' => $css]);
    $manifest['pages'] = [[
        'id' => 'pag_mcpcrit01', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => 'htm_mcpcrit01', 'type' => 'html', 'content' => $html],
        ],
    ]];
    $manifests->createVersion($app, $manifest, $user, 'seed');

    return $app->refresh();
}

it('judges a landing on demand: a bare page gets blocking must_fix', function () {
    mcpCritiqueLanding($this->user, '<div>hola</div>', '');

    SapiensServer::actingAs($this->user)
        ->tool(CritiqueLandingDesignTool::class, [
            'app_slug' => 'critique_me',
            'intent' => 'reservar clase muestra para un estudio de yoga',
        ])
        ->assertOk()
        ->assertSee('must_fix')
        ->assertSee('custom_css');
});

it('passes the deterministic floor for a bespoke landing', function () {
    $css = '.ld{--bg:#06070c;--cool:#4da3ff;--warm:#ffa56b;background:var(--bg);} .ld h1{font-size:clamp(2.5rem,6vw,4.5rem);letter-spacing:-.03em;} .ld .eyebrow{text-transform:uppercase;letter-spacing:.2em;}';
    $html = "<div class='ld'><section class='hero' data-sp-motion='ambient-field'><div data-sp-reveal><span class='eyebrow'>Yoga</span><h1>Respira, profesional estresado de la Condesa que no para</h1><p>Una descripcion suficientemente larga para superar el piso minimo del critico determinista.</p></div></section></div>";
    mcpCritiqueLanding($this->user, $html, $css);

    $response = SapiensServer::actingAs($this->user)
        ->tool(CritiqueLandingDesignTool::class, [
            'app_slug' => 'critique_me',
            'intent' => 'reservar clase muestra',
        ]);

    $response->assertOk();
    // In the test env no AI provider is configured, so the director degrades to
    // the deterministic floor — which this page passes.
    $response->assertSee('"judged_by":"deterministic"');
});
