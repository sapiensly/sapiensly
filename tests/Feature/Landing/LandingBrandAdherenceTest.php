<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Landing\LandingDesignCritic;
use Illuminate\Support\Str;

/**
 * The design gate must enforce the org Brandbook on landings WHEN it has content
 * — and be a clean no-op when the brandbook is empty (the design is then free).
 */
function brandCritic(): LandingDesignCritic
{
    // Deterministic path only (null user) — the AI deps are never touched.
    return new LandingDesignCritic(
        Mockery::mock(AiDefaults::class),
        Mockery::mock(AiProviderService::class),
    );
}

function appWithBrand(?array $brand): App
{
    $org = Organization::create([
        'name' => 'BrandCo',
        'slug' => 'brandco-'.uniqid(),
        'brand' => $brand ?? [],
    ]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    return App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'brand_lp_'.strtolower(Str::random(5)),
    ]);
}

// An off-brand stylesheet: a blue accent, no brand green, no --sp-accent vars.
const OFF_BRAND_CSS = '.lp{--bg:#06070c;--accent:#4da3ff;--ink:#eaf0ff;background:var(--bg);color:var(--ink);} .lp h1{font-size:clamp(2.5rem,6vw,4.5rem);letter-spacing:-.03em;} .lp .btn{background:#2e7bff;}';
const OFF_BRAND_HTML = "<div class='lp'><section class='hero' data-sp-reveal><h1>Una landing suficientemente larga para pasar el piso de doscientos caracteres del critico determinista.</h1></section></div>";

it('flags an off-brand palette as a tell when the brandbook sets an accent', function () {
    $app = appWithBrand(['accent_color' => '#00c774']);

    $r = brandCritic()->forSubject($app->id)->critique('demo booking', OFF_BRAND_HTML, OFF_BRAND_CSS, null);

    expect(implode(' | ', $r['tells']))->toContain('#00c774');
});

it('is a clean no-op when the brandbook is empty', function () {
    $app = appWithBrand(null);

    $r = brandCritic()->forSubject($app->id)->critique('demo booking', OFF_BRAND_HTML, OFF_BRAND_CSS, null);

    expect(implode(' | ', $r['tells']))->not->toContain('Brandbook accent');
});

it('does not flag when the css honors the brand accent', function () {
    $app = appWithBrand(['accent_color' => '#00c774']);
    $onBrandCss = str_replace('#4da3ff', '#00c774', OFF_BRAND_CSS);

    $r = brandCritic()->forSubject($app->id)->critique('demo booking', OFF_BRAND_HTML, $onBrandCss, null);

    expect(implode(' | ', $r['tells']))->not->toContain('Brandbook accent');
});

it('puts a BRAND ADHERENCE block in the director prompt only when the brandbook is set', function () {
    $branded = appWithBrand(['accent_color' => '#00c774', 'logo_url' => 'https://cdn.example.com/logo.svg']);
    $bare = appWithBrand(null);

    $method = new ReflectionMethod(LandingDesignCritic::class, 'buildPrompt');
    $method->setAccessible(true);

    $withBrand = $method->invoke(brandCritic()->forSubject($branded->id), 'intent', OFF_BRAND_HTML, OFF_BRAND_CSS, false, false);
    $withoutBrand = $method->invoke(brandCritic()->forSubject($bare->id), 'intent', OFF_BRAND_HTML, OFF_BRAND_CSS, false, false);

    expect($withBrand)->toContain('BRAND ADHERENCE')
        ->toContain('#00c774')
        ->toContain('brand LOGO')
        ->and($withoutBrand)->not->toContain('BRAND ADHERENCE');
});
