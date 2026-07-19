<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ManageLandingDomainTool;
use App\Models\App;
use App\Models\CustomDomain;
use App\Models\User;
use App\Services\Landing\CustomDomainService;
use App\Services\Landing\DnsResolver;
use App\Services\Manifest\AppManifestService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'promo_site',
        'name' => 'Promo',
        'public_slug' => 'promo_site',
        'published_at' => now(),
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($this->appModel);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    $manifest['pages'] = [[
        'id' => 'pag_mcpdom001', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => 'htm_mcpdom001', 'type' => 'html', 'content' => '<section><h1>x</h1></section>'],
        ],
    ]];
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
    $this->appModel->refresh();
});

it('connects, verifies to active, and disconnects a domain', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ManageLandingDomainTool::class, [
            'app_slug' => 'promo_site', 'action' => 'connect', 'hostname' => 'landing.acme.com',
        ])
        ->assertOk()
        ->assertSee('CNAME');

    expect(CustomDomain::query()->where('hostname', 'landing.acme.com')->exists())->toBeTrue();

    // Point the fake DNS at us → verify activates.
    app()->instance(DnsResolver::class, new class(app(CustomDomainService::class)->cnameTarget()) extends DnsResolver
    {
        public function __construct(private string $target) {}

        public function cname(string $hostname): ?string
        {
            return $this->target;
        }
    });

    SapiensServer::actingAs($this->user)
        ->tool(ManageLandingDomainTool::class, ['app_slug' => 'promo_site', 'action' => 'verify'])
        ->assertOk()
        ->assertSee('active');

    expect(CustomDomain::query()->firstOrFail()->status)->toBe('active');

    SapiensServer::actingAs($this->user)
        ->tool(ManageLandingDomainTool::class, ['app_slug' => 'promo_site', 'action' => 'disconnect'])
        ->assertOk();
    expect(CustomDomain::query()->count())->toBe(0);
});

it('reports status and errors cleanly without a domain', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ManageLandingDomainTool::class, ['app_slug' => 'promo_site', 'action' => 'status'])
        ->assertHasErrors();
});
