<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('switching to an organization redirects to the dashboard', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => null]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

    actingAs($user)
        ->from('/system/integrations')
        ->post('/account/switch', ['organization_id' => $org->id])
        ->assertRedirect('/dashboard');

    expect($user->fresh()->organization_id)->toBe($org->id);
});

test('switching back to personal also redirects to the dashboard', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

    actingAs($user)
        ->from('/documents')
        ->post('/account/switch', ['organization_id' => null])
        ->assertRedirect('/dashboard');

    expect($user->fresh()->organization_id)->toBeNull();
});

test('switching to an org without an active membership bounces back with an error', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => null]);
    // No membership created — switchAccount() should throw.

    actingAs($user)
        ->from('/documents')
        ->post('/account/switch', ['organization_id' => $org->id])
        ->assertRedirect('/documents')
        ->assertSessionHasErrors('organization_id');

    expect($user->fresh()->organization_id)->toBeNull();
});
