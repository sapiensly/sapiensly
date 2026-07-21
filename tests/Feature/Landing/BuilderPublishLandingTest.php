<?php

use App\Models\App;
use App\Models\CustomDomain;
use App\Models\User;
use App\Services\Landing\CustomDomainService;
use App\Services\Landing\DnsResolver;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

function builderLanding(User $user, bool $landing = true): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'ui_'.strtolower(Str::random(6)),
        'name' => 'Mi Landing',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    if ($landing) {
        $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    }
    $manifest['pages'] = [[
        'id' => 'pag_uipubhome1', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => 'htm_uipubhero', 'type' => 'html', 'content' => '<section><h1>Hola</h1></section>'],
        ],
    ]];
    $manifests->createVersion($app, $manifest, $user, 'seed');

    return $app->refresh();
}

it('publishes and unpublishes a landing from the builder UI endpoints', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = builderLanding($user);

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/publish-landing")
        ->assertOk()
        ->assertJsonPath('published', true)
        ->assertJsonPath('public_slug', str_replace('_', '-', $app->slug));

    expect($app->refresh()->public_slug)->toBe(str_replace('_', '-', $app->slug));
    $this->get("/l/{$app->public_slug}")->assertOk();

    $slug = $app->public_slug;
    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/unpublish-landing")
        ->assertOk()
        ->assertJsonPath('published', false);

    expect($app->refresh()->public_slug)->toBeNull();
    $this->get("/l/{$slug}")->assertNotFound();
});

it('422s when publishing a non-landing from the builder', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = builderLanding($user, landing: false);

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/publish-landing")
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_a_landing');
});

it('403s a user who cannot see the app', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $app = builderLanding($owner);
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)
        ->postJson("/apps/{$app->id}/builder/publish-landing")
        ->assertStatus(403);
});

it('connects, verifies and disconnects a custom domain from the builder UI', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = builderLanding($user);

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/landing-domain/connect", ['hostname' => 'landing.acme.com'])
        ->assertOk()
        ->assertJsonPath('hostname', 'landing.acme.com')
        ->assertJsonPath('status', 'pending');

    // Point the fake DNS at us → verify activates.
    app()->instance(DnsResolver::class, new class(app(CustomDomainService::class)->cnameTarget()) extends DnsResolver
    {
        public function __construct(private string $target) {}

        public function cname(string $hostname): ?string
        {
            return $this->target;
        }
    });

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/landing-domain/verify")
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/landing-domain/disconnect")
        ->assertOk();
    expect(CustomDomain::query()->count())->toBe(0);
});

it('rejects an invalid hostname with the service reason', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = builderLanding($user);

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/landing-domain/connect", ['hostname' => 'not a host'])
        ->assertStatus(422);
});

it('requires authentication', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = builderLanding($user);

    $this->postJson("/apps/{$app->id}/builder/publish-landing")->assertStatus(401);
});
