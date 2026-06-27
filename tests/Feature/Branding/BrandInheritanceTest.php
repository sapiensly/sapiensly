<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 — the organization Brandbook flows into the customizable surfaces both
 * ways: SEEDED into a new app/chatbot at creation, and applied as a LIVE fallback
 * at render so a brand change reaches surfaces that never overrode the value. A
 * per-surface override always wins.
 */
function bi_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(6)),
        'brand' => ['primary_color' => '#AB12CD', 'font' => 'serif', 'logo_url' => 'https://cdn.example.com/brand.png'],
    ]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);
});

/** A minimal valid manifest with one heading page and NO accent/font set. */
function bi_plainManifest(App $app): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => $app->name,
        'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => bi_id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
            'blocks' => [['id' => bi_id('blk'), 'type' => 'heading', 'content' => 'Hi']],
        ]],
        'permissions' => ['roles' => [['id' => bi_id('rol'), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX'],
    ];
}

it('seeds a new app manifest with the org brand', function () {
    $app = App::factory()->create(['user_id' => $this->owner->id, 'organization_id' => $this->org->id]);

    $manifest = app(AppManifestService::class)->initialManifest($app);

    expect($manifest['settings']['accent'])->toBe('#AB12CD')
        ->and($manifest['settings']['font'])->toBe('serif')
        ->and($manifest['settings']['brand']['logo'])->toBe('https://cdn.example.com/brand.png');
});

it('applies the brand as a live fallback at app runtime', function () {
    $app = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'branded', 'name' => 'Branded', 'visibility' => 'organization',
    ]);
    // A manifest with no accent (built directly, bypassing the seed).
    app(AppManifestService::class)->createVersion($app, bi_plainManifest($app), $this->owner);

    $this->actingAs($this->owner)
        ->get('/r/branded')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('manifest.settings.accent', '#AB12CD')   // filled live
            ->where('manifest.settings.font', 'serif'));
});

it('does not override an app that set its own accent', function () {
    $app = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'owncolor', 'name' => 'Own', 'visibility' => 'organization',
    ]);
    $manifest = bi_plainManifest($app);
    $manifest['settings']['accent'] = '#001122';
    app(AppManifestService::class)->createVersion($app, $manifest, $this->owner);

    $this->actingAs($this->owner)
        ->get('/r/owncolor')
        ->assertInertia(fn ($page) => $page->where('manifest.settings.accent', '#001122'));
});

it('seeds a new chatbot config with the org brand', function () {
    $config = Chatbot::defaultConfigForOrganization($this->org);

    expect($config['appearance']['primary_color'])->toBe('#AB12CD')
        ->and($config['appearance']['logo_url'])->toBe('https://cdn.example.com/brand.png');
});

it('applies the brand as a live fallback on the widget appearance', function () {
    // A bot whose stored appearance is still at the built-in defaults (e.g.
    // created before the brand existed).
    $chatbot = Chatbot::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'name' => 'Helper',
        'config' => Chatbot::getDefaultConfig(),
    ]);

    $appearance = $chatbot->getAppearanceConfig();

    expect($appearance['primary_color'])->toBe('#AB12CD')          // default → filled
        ->and($appearance['logo_url'])->toBe('https://cdn.example.com/brand.png');
});

it('keeps a chatbot custom colour over the brand', function () {
    $config = Chatbot::getDefaultConfig();
    $config['appearance']['primary_color'] = '#00FF00'; // customized away from default

    $chatbot = Chatbot::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'name' => 'Custom',
        'config' => $config,
    ]);

    expect($chatbot->getAppearanceConfig()['primary_color'])->toBe('#00FF00');
});
