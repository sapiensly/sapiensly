<?php

use App\Models\App;
use App\Models\CustomDomain;
use App\Models\User;
use App\Services\Landing\CustomDomainService;
use App\Services\Landing\DnsResolver;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function domainLanding(bool $published = true): App
{
    $user = User::factory()->create();
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'dom_'.strtolower(Str::random(6)),
        'name' => 'Dominio',
        'public_slug' => $published ? 'dom_'.strtolower(Str::random(8)) : null,
        'published_at' => $published ? now() : null,
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    $manifest['pages'] = [[
        'id' => 'pag_domhome001', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => 'htm_domhero01', 'type' => 'html', 'content' => '<section><h1>Mi dominio</h1></section>'],
        ],
    ]];
    $manifests->createVersion($app, $manifest, $user, 'landing');

    return $app->refresh();
}

function fakeDns(?string $cnameTarget): void
{
    app()->instance(DnsResolver::class, new class($cnameTarget) extends DnsResolver
    {
        public function __construct(private ?string $target) {}

        public function cname(string $hostname): ?string
        {
            return $this->target;
        }
    });
}

// ---------- serving by Host header ----------

it('serves the landing at the root of an active custom domain', function () {
    $app = domainLanding();
    CustomDomain::create([
        'organization_id' => $app->organization_id, 'user_id' => $app->user_id, 'app_id' => $app->id,
        'hostname' => 'landing.acme.com', 'status' => 'active', 'verified_at' => now(),
    ]);

    $this->get('http://landing.acme.com/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('runtime/Page')
            ->where('app.kind', 'landing')
            ->where('publicSurface', true)
        );
});

it('keeps the platform root redirect on non-custom hosts', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('does not serve a pending domain', function () {
    $app = domainLanding();
    CustomDomain::create([
        'organization_id' => $app->organization_id, 'user_id' => $app->user_id, 'app_id' => $app->id,
        'hostname' => 'landing.acme.com', 'status' => 'pending',
    ]);

    $this->get('http://landing.acme.com/')->assertRedirect(route('login'));
});

it('404s an active domain whose landing was unpublished (never bounce to platform login)', function () {
    $app = domainLanding(published: false);
    CustomDomain::create([
        'organization_id' => $app->organization_id, 'user_id' => $app->user_id, 'app_id' => $app->id,
        'hostname' => 'landing.acme.com', 'status' => 'active', 'verified_at' => now(),
    ]);

    $this->get('http://landing.acme.com/')->assertNotFound();
});

// ---------- connect / verify / disconnect ----------

it('connects a hostname and verifies it active once the CNAME points at us', function () {
    $app = domainLanding();
    $service = fn () => app(CustomDomainService::class);

    $domain = $service()->connect($app, 'Landing.Acme.com');
    expect($domain->hostname)->toBe('landing.acme.com')
        ->and($domain->status)->toBe('pending');

    // Wrong CNAME → still pending, with an actionable reason.
    fakeDns('other.example.com');
    $result = $service()->verify($domain);
    expect($result['domain']->status)->toBe('pending')
        ->and($result['checks']['dns'])->toContain('wrong target');

    // Correct CNAME → active.
    fakeDns($service()->cnameTarget());
    $result = $service()->verify($result['domain']);
    expect($result['domain']->status)->toBe('active')
        ->and($result['domain']->verified_at)->not->toBeNull();
});

it('rejects invalid hostnames, the platform host, duplicates and non-landings', function () {
    $app = domainLanding();
    $service = app(CustomDomainService::class);

    expect(fn () => $service->connect($app, 'not a host'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->connect($app, $service->cnameTarget()))->toThrow(InvalidArgumentException::class);

    $service->connect($app, 'landing.acme.com');
    expect(fn () => $service->connect($app, 'landing.acme.com'))->toThrow(InvalidArgumentException::class, 'already connected');

    $other = App::factory()->create(['kind' => 'app']);
    expect(fn () => $service->connect($other->refresh(), 'x.acme.com'))->toThrow(InvalidArgumentException::class, 'Only landings');
});

it('registers and tears down the Cloudflare hostname when the SaaS API is configured', function () {
    config([
        'services.cloudflare_saas.api_token' => 'cf-token',
        'services.cloudflare_saas.zone_id' => 'zone-1',
        'services.cloudflare_saas.cname_target' => 'landings.sapiensly.com',
    ]);
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/custom_hostnames' => Http::response(['result' => ['id' => 'cfh-1', 'status' => 'pending']]),
        'api.cloudflare.com/client/v4/zones/zone-1/custom_hostnames/cfh-1' => Http::response(['result' => ['id' => 'cfh-1', 'status' => 'active', 'ssl' => ['status' => 'active']]]),
    ]);

    $app = domainLanding();
    $service = app(CustomDomainService::class);

    $domain = $service->connect($app, 'landing.acme.com');
    expect($domain->cf_hostname_id)->toBe('cfh-1');

    // DNS ok + CF active → active.
    fakeDns('landings.sapiensly.com');
    $result = app(CustomDomainService::class)->verify($domain);
    expect($result['domain']->status)->toBe('active')
        ->and($result['checks']['cloudflare'])->toContain('ok');

    app(CustomDomainService::class)->disconnect($result['domain']);
    expect(CustomDomain::query()->count())->toBe(0);
    Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), 'cfh-1'));
});
