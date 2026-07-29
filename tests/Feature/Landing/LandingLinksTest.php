<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * Where a landing's buttons go.
 *
 * The shape of this feature comes from a real rebuilt landing: nine anchors to
 * `#waitlist` scattered across five sections, ten footer links left on `href="#"`,
 * and CTAs the model wrote as `<button>` — which the sanitiser forces inert, so
 * they were dead on the published page. The panel is therefore grouped by
 * destination (one edit moves all nine) and has to be honest about the two
 * silent failures.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->manifests = app(AppManifestService::class);
    $this->app_ = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
});

/**
 * @param  array<string, string>  $blocks  block id => html content
 * @return array<string, mixed>
 */
function linksManifest(App $app, array $blocks): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'mi_landing',
        'name' => $app->name,
        'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pg_landing',
            'slug' => 'home',
            'path' => '/',
            'name' => 'Home',
            'blocks' => array_map(
                static fn (string $id, string $content): array => ['id' => $id, 'type' => 'html', 'content' => $content],
                array_keys($blocks),
                array_values($blocks),
            ),
        ]],
        'permissions' => [
            'roles' => [['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin']],
        ],
        'settings' => ['surface' => 'landing'],
    ];
}

function seedLinksLanding(mixed $ctx, array $blocks): void
{
    $ctx->manifests->createVersion($ctx->app_, linksManifest($ctx->app_, $blocks), $ctx->user);
}

/** @return array<string, mixed> */
function linksActiveManifest(mixed $ctx): array
{
    return $ctx->manifests->getActiveManifest($ctx->app_->fresh()) ?? [];
}

function linksVersionCount(mixed $ctx): int
{
    return $ctx->app_->versions()->count();
}

it('groups every link by where it points, biggest group first', function () {
    seedLinksLanding($this, [
        'blk_header' => '<header id="top"><a href="#waitlist" class="cta">Empieza</a><a href="#pricing">Precios</a></header>',
        'blk_pricing' => '<section id="pricing"><a href="#waitlist">Gratis</a><a href="#waitlist">Pro</a></section>',
    ]);

    $groups = $this->actingAs($this->user)
        ->getJson("/apps/{$this->app_->id}/builder/links")
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(2);
    expect($groups[0]['target'])->toBe('#waitlist');
    expect($groups[0]['kind'])->toBe('anchor');
    expect($groups[0]['count'])->toBe(3);
    // Addressable across sections: the group spans two blocks.
    expect(collect($groups[0]['links'])->pluck('block_id')->unique()->values()->all())
        ->toBe(['blk_header', 'blk_pricing']);
    expect($groups[0]['links'][0]['label'])->toBe('Empieza');
});

it('surfaces placeholder links and inert buttons as having no destination', function () {
    seedLinksLanding($this, [
        'blk_footer' => '<footer><a href="#">Quickstart</a><a href="#">About</a><button class="cta">Habla con nosotros</button></footer>',
    ]);

    $groups = $this->actingAs($this->user)
        ->getJson("/apps/{$this->app_->id}/builder/links")
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(1);
    expect($groups[0]['target'])->toBe('');
    expect($groups[0]['kind'])->toBe('none');
    expect($groups[0]['count'])->toBe(3);
    // The button is called out separately: it is dead for a different reason,
    // and fixing it changes the element, not just the attribute.
    expect($groups[0]['inert_count'])->toBe(1);
    expect($groups[0]['links'][2]['element'])->toBe('button');
});

it('offers the page ids as destinations', function () {
    seedLinksLanding($this, [
        'blk_one' => '<section id="pricing"><a href="#">x</a></section>',
        'blk_two' => '<section id="council"><div data-sp-slot="lead_form" id="waitlist"></div></section>',
    ]);

    expect($this->actingAs($this->user)
        ->getJson("/apps/{$this->app_->id}/builder/links")
        ->assertOk()
        ->json('anchors'))->toBe(['#council', '#pricing', '#waitlist']);
});

it('retargets a whole group across sections in a single version', function () {
    seedLinksLanding($this, [
        'blk_header' => '<header><a href="#waitlist">A</a></header>',
        'blk_cta' => '<section><a href="#waitlist">B</a><a href="#pricing">C</a></section>',
    ]);
    $before = linksVersionCount($this);

    $ids = collect($this->actingAs($this->user)->getJson("/apps/{$this->app_->id}/builder/links")->json('groups'))
        ->firstWhere('target', '#waitlist')['links'];

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->app_->id}/builder/links/retarget", [
            'link_ids' => collect($ids)->pluck('id')->all(),
            'to' => 'https://app.sapiensly.ai/register',
        ])
        ->assertOk()
        ->assertJson(['ok' => true, 'changed' => 2]);

    // Two sections, one intention, one version.
    expect(linksVersionCount($this))->toBe($before + 1);

    $groups = collect($this->actingAs($this->user)->getJson("/apps/{$this->app_->id}/builder/links")->json('groups'));
    expect($groups->firstWhere('target', 'https://app.sapiensly.ai/register')['count'])->toBe(2);
    expect($groups->firstWhere('target', '#waitlist'))->toBeNull();
    // Untouched links stay untouched.
    expect($groups->firstWhere('target', '#pricing')['count'])->toBe(1);
});

it('turns an inert button into a real link, keeping its design', function () {
    seedLinksLanding($this, [
        'blk_cta' => '<section><button class="lp-btn-primary" id="go">Habla con <strong>nosotros</strong></button></section>',
    ]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->app_->id}/builder/links/retarget", [
            'link_ids' => ['blk_cta:0'],
            'to' => 'mailto:hola@acme.test',
        ])
        ->assertOk()
        ->assertJson(['changed' => 1]);

    $content = linksActiveManifest($this)['pages'][0]['blocks'][0]['content'];

    expect($content)->toContain('<a')
        ->and($content)->not->toContain('<button')
        // Classes, id and children survive — the page must look identical.
        ->and($content)->toContain('class="lp-btn-primary"')
        ->and($content)->toContain('id="go"')
        ->and($content)->toContain('<strong>nosotros</strong>')
        ->and($content)->toContain('href="mailto:hola@acme.test"');
});

it('keeps the sanitiser as the boundary on the way in', function () {
    seedLinksLanding($this, ['blk_cta' => '<section><a href="#">Ir</a></section>']);

    // Rejected before it is written…
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->app_->id}/builder/links/retarget", [
            'link_ids' => ['blk_cta:0'],
            'to' => 'javascript:alert(1)',
        ])
        ->assertStatus(422)
        ->assertJson(['error' => 'invalid_target']);

    // …and a bare '#' is the placeholder we are removing, not a destination.
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->app_->id}/builder/links/retarget", [
            'link_ids' => ['blk_cta:0'],
            'to' => '#',
        ])
        ->assertStatus(422)
        ->assertJson(['error' => 'invalid_target']);

    // An external destination still gets hardened by the save-time sanitiser.
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->app_->id}/builder/links/retarget", [
            'link_ids' => ['blk_cta:0'],
            'to' => 'https://example.test/precios',
        ])
        ->assertOk();

    $content = linksActiveManifest($this)['pages'][0]['blocks'][0]['content'];
    expect($content)->toContain('rel="noopener noreferrer nofollow"')
        ->and($content)->toContain('target="_blank"');
});

it('reports a stale link instead of writing a version that changes nothing', function () {
    seedLinksLanding($this, ['blk_cta' => '<section><a href="#a">Ir</a></section>']);
    $before = linksVersionCount($this);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->app_->id}/builder/links/retarget", [
            'link_ids' => ['blk_cta:7'],
            'to' => '#b',
        ])
        ->assertStatus(404)
        ->assertJson(['error' => 'not_found']);

    expect(linksVersionCount($this))->toBe($before);
});
