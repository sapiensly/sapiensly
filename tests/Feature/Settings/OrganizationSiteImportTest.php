<?php

use App\Ai\ChatAgent;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Site\SiteFetch;
use App\Support\Site\SiteUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    config()->set('security.ssrf.enabled', false); // the guard has its own tests

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);
});

function fakeSitePage(): void
{
    Http::fake(['*' => Http::response(
        '<html><head><title>Acme</title>'
        .'<meta name="theme-color" content="#0f766e">'
        .'<link rel="apple-touch-icon" href="/touch.png">'
        .'</head><body>'.siteCopy().'</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
}

/**
 * The whole point of the unified endpoint: the admin types the URL once and both
 * books come back proposed from the same reading of the page.
 */
it('proposes both books from one reading of the site', function () {
    fakeSitePage();
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Moves refrigerated freight.'])]);

    $response = $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('read', true)
        ->assertJsonPath('reason', SiteFetch::OK)
        ->assertJsonPath('brand.proposal.accent_color', '#0f766e')
        ->assertJsonPath('context.profile.descriptor', 'Moves refrigerated freight.');

    expect($response->json('brand.proposal.icon_url'))->toBe('https://acme.example/touch.png');

    // Proposing writes nothing: neither book moved.
    expect($this->org->fresh()->brand)->toBeNull()
        ->and(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();

    // One reading, not one per book.
    Http::assertSentCount(1);
});

/**
 * The papercut this endpoint was rebuilt around: a `url` validation rule turned
 * the way people actually type an address into "that site could not be read".
 */
it('reads the address the way a human typed it', function () {
    fakeSitePage();
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Freight.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => '  acme.example  '])
        ->assertOk()
        ->assertJsonPath('url', 'https://acme.example')
        ->assertJsonPath('read', true);
});

it('normalizes what people type into something fetchable', function (?string $typed, ?string $expected) {
    expect(SiteUrl::normalize($typed))->toBe($expected);
})->with([
    ['acme.example', 'https://acme.example'],
    ['  acme.example/es  ', 'https://acme.example/es'],
    ['http://acme.example', 'http://acme.example'],
    ['HTTPS://Acme.example', 'HTTPS://Acme.example'],
    ['//acme.example', 'https://acme.example'],
    // Not a website, and never coerced into one.
    ['javascript:alert(1)', null],
    ['file:///etc/passwd', null],
    ['ftp://acme.example', null],
    // A dotless host is an intranet name or a typo, not the company's site.
    ['localhost', null],
    ['acme', null],
    ['', null],
    [null, null],
]);

/**
 * Four ways a fetch fails, four things the user would do about it. Collapsing
 * them into one message is what the old flow did.
 */
it('says which way the read failed', function (callable $arrange, string $website, string $reason) {
    $arrange();
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'From the brief.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => $website])
        ->assertOk()
        ->assertJsonPath('read', false)
        ->assertJsonPath('reason', $reason);
})->with([
    'not a website' => [fn () => null, 'javascript:alert(1)', SiteFetch::INVALID_URL],
    'nothing typed' => [fn () => null, '', SiteFetch::NO_URL],
    'host did not answer' => [
        fn () => Http::fake(['*' => Http::response('nope', 500)]),
        'https://acme.example',
        SiteFetch::UNREACHABLE,
    ],
    'not a page' => [
        fn () => Http::fake(['*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]),
        'https://acme.example/brochure.pdf',
        SiteFetch::NOT_HTML,
    ],
]);

/**
 * The second book must not cost a second model call — that is the difference
 * between "one import" and "two imports that happen to share a URL".
 */
it('reuses the draft when the other book asks for the same site', function () {
    fakeSitePage();
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Moves refrigerated freight.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('reused', false);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('reused', true)
        ->assertJsonPath('context.profile.descriptor', 'Moves refrigerated freight.');
});

/** A draft the model failed to produce is a retry, not something to replay for half an hour. */
it('does not reuse a draft that was never generated', function () {
    fakeSitePage();
    Ai::fakeAgent(ChatAgent::class, ['not json at all']);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertJsonPath('context.generated', false);

    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Second time lucky.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertJsonPath('reused', false)
        ->assertJsonPath('context.profile.descriptor', 'Second time lucky.');
});

/**
 * The diff is recomputed against what is stored right now, never cached with the
 * draft — otherwise a reused draft would report a conflict the admin just resolved.
 */
it('recomputes the diff against what is stored, even on a reused draft', function () {
    fakeSitePage();
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'What the website says.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertJsonPath('context.has_conflicts', false);

    $row = OrganizationAiContext::firstOrNew(['organization_id' => $this->org->id])
        ->setRelation('organization', $this->org);
    $row->fill(['profile' => ['descriptor' => 'What we wrote ourselves.']])->recompile()->save();

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertJsonPath('reused', true)
        ->assertJsonPath('context.has_conflicts', true);
});

/** The Brandbook page has no URL of its own; it starts from the one the Contextbook knows. */
it('offers the brandbook the website the contextbook already recorded', function () {
    $row = OrganizationAiContext::firstOrNew(['organization_id' => $this->org->id])
        ->setRelation('organization', $this->org);
    $row->fill(['profile' => ['website' => 'https://acme.example']])->recompile()->save();

    $this->actingAs($this->owner)
        ->get('/settings/organization/brand')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('website', 'https://acme.example'));
});

/** Whichever page imported, the other one can offer that reading instead of asking again. */
it('remembers the last import for the other book', function () {
    fakeSitePage();
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Freight.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'acme.example'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->get('/settings/organization/context')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('lastImport.url', 'https://acme.example'));
});

it('keeps the import away from a plain member', function () {
    $member = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $member->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($member)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertForbidden();
});

/**
 * The case aerobit.com made concrete: a client-rendered site answers 200 with a
 * good title and favicon and a body holding only a browser notice. Nothing about
 * that reads as a failure, so an empty Contextbook next to "drafted from: your
 * website" reads as a broken feature instead of a page with no words on it.
 */
it('says the page carried no readable text instead of returning an empty draft', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Aerobit</title><link rel="apple-touch-icon" href="/touch.png"></head>'
        .'<body>You are using an outdated browser. Please upgrade your browser.</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Invented from a name.'])]);

    $response = $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('read', true)
        ->assertJsonPath('context.site_has_prose', false)
        // The website is not claimed as a source it could not be.
        ->assertJsonPath('context.sources', [])
        ->assertJsonPath('context.generated', false);

    // And the brand signals in the markup were still read.
    expect($response->json('brand.proposal.icon_url'))->toBe('https://acme.example/touch.png');
});

/** A brief is material even when the page is not, so the draft still happens. */
it('still drafts from the brief when the page carries no text', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Aerobit</title></head><body>Outdated browser.</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Builds drone hardware.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', [
            'website' => 'https://acme.example',
            'brief' => 'We build industrial drone hardware for mining surveys.',
        ])
        ->assertOk()
        ->assertJsonPath('context.site_has_prose', false)
        ->assertJsonPath('context.sources', ['brief'])
        ->assertJsonPath('context.profile.descriptor', 'Builds drone hardware.');
});

/** A page with real copy is unaffected — the floor separates words from no words. */
it('treats a page with real copy as material', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Acme</title></head><body>'
        .str_repeat('Acme moves refrigerated freight for food producers across the country. ', 8)
        .'</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Moves refrigerated freight.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('context.site_has_prose', true)
        ->assertJsonPath('context.sources', ['website']);
});
