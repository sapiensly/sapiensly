<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Branding\BrandProposalService;
use App\Support\Draft\DraftDiff;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

function fakeBrandSite(string $themeColor = '#0f766e'): void
{
    Http::fake(['*' => Http::response(
        '<html><head><meta name="theme-color" content="'.$themeColor.'">'
        .'<meta name="color-scheme" content="dark">'
        .'<link rel="apple-touch-icon" href="/touch.png">'
        .'<meta property="og:image" content="https://acme.example/logo.png">'
        .'<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Fraunces">'
        .'</head><body>Acme</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
}

it('proposes a brandbook from the website signals', function () {
    fakeBrandSite();

    $result = app(BrandProposalService::class)->propose($this->org, 'https://acme.example');

    expect($result['proposal'])->toBe([
        'icon_url' => 'https://acme.example/touch.png',
        'logo_url' => 'https://acme.example/logo.png',
        'accent_color' => '#0f766e',
        'font' => 'serif',
        'theme' => 'dark',
    ])
        ->and($result['has_conflicts'])->toBeFalse()
        // The accent comes back expanded, so the user compares a real palette.
        ->and($result['palette']['ramp']['500'])->toBe('#0f766e');
});

/**
 * The rule this whole flow exists for: a brand that is already set is never
 * quietly replaced by whatever the website happens to say today.
 */
it('never overwrites a brand that is already set — it reports the clash', function () {
    $this->org->update(['brand' => ['accent_color' => '#be185d', 'font' => 'mono']]);
    fakeBrandSite();

    $result = app(BrandProposalService::class)->propose($this->org, 'https://acme.example');
    $byField = collect($result['diff'])->keyBy('field');

    expect($result['has_conflicts'])->toBeTrue()
        ->and($byField['accent_color']['status'])->toBe(DraftDiff::CONFLICT)
        ->and($byField['accent_color']['current'])->toBe('#be185d')
        ->and($byField['font']['status'])->toBe(DraftDiff::CONFLICT)
        // Untouched fields are still free to fill.
        ->and($byField['logo_url']['status'])->toBe(DraftDiff::NEW);

    // And nothing was written.
    expect($this->org->fresh()->brandbook()->accentColor)->toBe('#be185d')
        ->and($this->org->fresh()->brandbook()->font)->toBe('mono');
});

it('refuses a theme colour that cannot carry a button, and says why', function () {
    fakeBrandSite('#fafafa');

    $result = app(BrandProposalService::class)->propose($this->org, 'https://acme.example');

    expect($result['proposal'])->not->toHaveKey('accent_color')
        ->and($result['palette'])->toBeNull()
        ->and($result['notes'][0])->toContain('#fafafa');
});

it('judges whether a colour can serve as an accent', function (string $hex, bool $usable) {
    expect(BrandProposalService::isUsableAccent($hex))->toBe($usable);
})->with([
    ['#0f766e', true],
    ['#4f46e5', true],
    ['#ffffff', false],   // near-white
    ['#000000', false],   // near-black
    ['#6b7280', false],   // grey
    ['#fafafa', false],
]);

it('maps a font stack onto the four families the brandbook offers', function (array $families, ?string $expected) {
    expect(BrandProposalService::fontFrom($families))->toBe($expected);
})->with([
    [['Fraunces'], 'serif'],
    [['IBM Plex Mono'], 'mono'],
    [['Varela Round'], 'rounded'],
    [['Roboto Slab'], 'serif'],
    [['Open Sans'], 'sans'],
    // A name nobody listed is still a real choice; sans is what most are.
    [['Wingfoil Neue'], 'sans'],
    [[], null],
]);

it('says plainly when the site carries nothing usable', function () {
    Http::fake(['*' => Http::response('<html><body>Just words.</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $result = app(BrandProposalService::class)->propose($this->org, 'https://acme.example');

    expect($result['read'])->toBeTrue()
        ->and($result['proposal'])->toBe([])
        ->and($result['notes'][0])->toContain('no brand signals');
});

it('exposes the proposal over the settings endpoint without writing', function () {
    fakeBrandSite();

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('brand.proposal.accent_color', '#0f766e')
        ->assertJsonStructure(['brand' => ['read', 'source', 'proposal', 'diff', 'has_conflicts', 'palette', 'notes']]);

    expect($this->org->fresh()->brand)->toBeNull();
});

it('keeps the proposal away from a plain member', function () {
    $member = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $member->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($member)
        ->postJson('/settings/organization/site-import', ['website' => 'https://acme.example'])
        ->assertForbidden();
});
