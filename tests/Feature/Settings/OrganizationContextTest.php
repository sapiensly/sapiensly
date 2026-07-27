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

it('renders the contextbook page for an org admin with the stored context and its cost', function () {
    OrganizationAiContext::create([
        'organization_id' => $this->org->id,
        'profile' => OrganizationContext::fromArray(['descriptor' => 'Moves freight.', 'currency' => 'MXN'])->toArray(),
    ]);

    $this->actingAs($this->owner)
        ->get('/settings/organization/context')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/OrganizationContext')
            ->where('context.descriptor', 'Moves freight.')
            ->where('context.currency', 'MXN')
            ->where('maxTokens', OrganizationContext::MAX_TOKENS)
            ->where('enabled', true)
            // The page shows the exact block the models will read.
            ->where('preview', fn (string $preview) => str_contains($preview, 'Moves freight.')));
});

it('renders an empty contextbook without a stored row', function () {
    $this->actingAs($this->owner)
        ->get('/settings/organization/context')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('context.descriptor', null)
            ->where('enabled', true));
});

it('saves the context and compiles the block on write', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/context', [
            'descriptor' => 'Moves refrigerated freight.',
            'timezone' => 'America/Mexico_City',
            'currency' => 'mxn',
            'glossary' => [['term' => 'guia', 'meaning' => 'the shipment document']],
            'never' => ['Quote prices'],
        ])
        ->assertRedirect();

    $row = OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail();

    expect($row->profile['currency'])->toBe('MXN')
        // The prompt path only ever reads the compiled columns.
        ->and($row->compiled_prompt)->toContain('Moves refrigerated freight.')
        ->toContain('"guia": the shipment document')
        ->toContain('- Quote prices')
        ->and($row->compiled_tokens)->toBeGreaterThan(0)
        ->and($row->updated_by_id)->toBe($this->owner->id)
        ->and($row->injectableBlock())->not->toBeNull();
});

it('leaves fields the form did not touch alone and clears the ones it blanked', function () {
    OrganizationAiContext::create([
        'organization_id' => $this->org->id,
        'profile' => OrganizationContext::fromArray(['descriptor' => 'Old.', 'industry' => 'logistics'])->toArray(),
    ]);

    $this->actingAs($this->owner)
        ->put('/settings/organization/context', ['descriptor' => 'New.', 'industry' => null])
        ->assertRedirect();

    $row = OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail();

    expect($row->profile['descriptor'])->toBe('New.')
        ->and($row->profile['industry'])->toBeNull();
});

it('rejects a context that would not fit the per-call token budget', function () {
    // Twenty maxed-out glossary entries and ten boundaries blow past the cap.
    $this->actingAs($this->owner)
        ->put('/settings/organization/context', [
            'descriptor' => str_repeat('a', 240),
            'audience' => str_repeat('b', 400),
            'glossary' => array_fill(0, 20, ['term' => str_repeat('t', 40), 'meaning' => str_repeat('m', 160)]),
            'offerings' => array_fill(0, 10, ['name' => str_repeat('n', 60), 'description' => str_repeat('d', 160)]),
            'never' => array_fill(0, 10, str_repeat('x', 160)),
        ])
        ->assertSessionHasErrors('descriptor');

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('rejects an unusable timezone, currency or url', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/context', [
            'timezone' => 'Mars/Olympus_Mons',
            'currency' => 'pesos',
            'website' => 'not-a-url',
        ])
        ->assertSessionHasErrors(['timezone', 'currency', 'website']);
});

it('previews the block for unsaved form state without storing anything', function () {
    $this->actingAs($this->owner)
        ->postJson('/settings/organization/context/preview', ['descriptor' => 'Draft only.'])
        ->assertOk()
        ->assertJsonPath('max_tokens', OrganizationContext::MAX_TOKENS)
        ->assertJsonStructure(['preview', 'tokens', 'max_tokens']);

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('keeps the contextbook out of reach of a plain member', function () {
    $this->actingAs($this->member)->get('/settings/organization/context')->assertForbidden();
    $this->actingAs($this->member)
        ->put('/settings/organization/context', ['descriptor' => 'Sneaky.'])
        ->assertForbidden();
});

it('is unreachable for a personal account', function () {
    $personal = User::factory()->create(['email_verified_at' => now(), 'organization_id' => null]);

    $this->actingAs($personal)->get('/settings/organization/context')->assertForbidden();
});
