<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Context\OrganizationContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two books, one screen, one save. What is worth testing here is precisely
 * what the split used to make impossible: a single write that persists both, and
 * a refusal that leaves neither half applied.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);

    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);

    $this->member = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->member->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);
});

it('renders both books and the general facts they share', function () {
    $this->org->update(['brand' => ['accent_color' => '#123456']]);
    OrganizationAiContext::create([
        'organization_id' => $this->org->id,
        'profile' => OrganizationContext::fromArray([
            'descriptor' => 'Moves freight.',
            'website' => 'https://acme.example',
        ])->toArray(),
    ]);

    $this->actingAs($this->owner)
        ->get('/settings/organization/identity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/OrganizationIdentity')
            ->where('brand.accent_color', '#123456')
            ->where('palette.ramp.500', '#123456')
            ->where('context.descriptor', 'Moves freight.')
            // The URL both books are read from, asked for once.
            ->where('context.website', 'https://acme.example')
            ->where('enabled', true)
            ->where('maxTokens', OrganizationContext::MAX_TOKENS)
            ->has('formalityOptions')
            ->has('unitOptions'));
});

it('saves the brandbook and the contextbook in one write', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/identity', [
            'accent_color' => '#1A2B3C',
            'font' => 'serif',
            'descriptor' => 'Moves refrigerated freight.',
            'industry' => 'logistics',
            'currency' => 'mxn',
            'website' => 'https://acme.example',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $brand = $this->org->refresh()->brandbook();
    $row = OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail();

    expect($brand->accentColor)->toBe('#1A2B3C')
        ->and($brand->font)->toBe('serif')
        ->and($row->profile['descriptor'])->toBe('Moves refrigerated freight.')
        ->and($row->profile['currency'])->toBe('MXN')
        // The prompt path only ever reads the compiled columns.
        ->and($row->compiled_prompt)->toContain('Moves refrigerated freight.')
        ->and($row->updated_by_id)->toBe($this->owner->id);
});

/**
 * The reason the budget check runs before either save: one screen, one save, so a
 * Contextbook that cannot be stored must not take the brand half with it — nor
 * leave it applied while the page reports a failure.
 */
it('refuses the whole save when the contextbook would not fit the token budget', function () {
    $this->org->update(['brand' => ['accent_color' => '#111111']]);

    $this->actingAs($this->owner)
        ->put('/settings/organization/identity', [
            'accent_color' => '#222222',
            'descriptor' => str_repeat('a', 240),
            'audience' => str_repeat('b', 400),
            'glossary' => array_fill(0, 20, ['term' => str_repeat('t', 40), 'meaning' => str_repeat('m', 160)]),
            'offerings' => array_fill(0, 10, ['name' => str_repeat('n', 60), 'description' => str_repeat('d', 160)]),
            'never' => array_fill(0, 10, str_repeat('x', 160)),
        ])
        ->assertSessionHasErrors('descriptor');

    expect($this->org->refresh()->brandbook()->accentColor)->toBe('#111111')
        ->and(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

/** An invalid colour is an objection about the Brandbook tab, and it saves nothing. */
it('refuses the whole save when the brandbook is invalid', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/identity', [
            'accent_color' => 'blue',
            'descriptor' => 'Moves freight.',
        ])
        ->assertSessionHasErrors('accent_color');

    expect($this->org->refresh()->brand)->toBeNull()
        ->and(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

/**
 * A save that carries only one book leaves the other alone. The screen always
 * submits both, but merging over what is stored is what makes that safe rather
 * than a way to erase half the identity from a partial request.
 */
it('leaves the contextbook alone when only the brand is submitted', function () {
    OrganizationAiContext::create([
        'organization_id' => $this->org->id,
        'profile' => OrganizationContext::fromArray(['descriptor' => 'Moves freight.'])->toArray(),
    ]);

    $this->actingAs($this->owner)
        ->put('/settings/organization/identity', ['accent_color' => '#333333'])
        ->assertRedirect();

    $row = OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail();

    expect($this->org->refresh()->brandbook()->accentColor)->toBe('#333333')
        ->and($row->profile['descriptor'])->toBe('Moves freight.');
});

it('does not invent a contextbook for an organization that has none', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/identity', ['accent_color' => '#444444'])
        ->assertRedirect();

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('keeps the contextbook switch when the brand alone is saved', function () {
    OrganizationAiContext::create([
        'organization_id' => $this->org->id,
        'profile' => OrganizationContext::fromArray(['descriptor' => 'Moves freight.'])->toArray(),
        'enabled' => false,
    ]);

    $this->actingAs($this->owner)
        ->put('/settings/organization/identity', ['descriptor' => 'Moves freight.'])
        ->assertRedirect();

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail()->enabled)->toBeFalse();
});

/** The books were a page each: those addresses are in histories and old links. */
it('sends the old per-book addresses to their tab', function () {
    $this->actingAs($this->owner)
        ->get('/settings/organization/brand')
        ->assertRedirect('/settings/organization/identity?tab=brand');

    $this->actingAs($this->owner)
        ->get('/settings/organization/context')
        ->assertRedirect('/settings/organization/identity?tab=context');
});

it('keeps the organization identity out of reach of a plain member', function () {
    $this->actingAs($this->member)->get('/settings/organization/identity')->assertForbidden();

    $this->actingAs($this->member)
        ->put('/settings/organization/identity', ['descriptor' => 'Sneaky.', 'accent_color' => '#000000'])
        ->assertForbidden();

    expect($this->org->refresh()->brand)->toBeNull();
});

it('is unreachable for a personal account', function () {
    $personal = User::factory()->create(['email_verified_at' => now(), 'organization_id' => null]);

    $this->actingAs($personal)->get('/settings/organization/identity')->assertForbidden();
});
