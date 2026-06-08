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

function makeOrganizationWithOwner(): array
{
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $owner = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

    return [$org, $owner];
}

test('the owner can soft delete the organization', function () {
    [$org, $owner] = makeOrganizationWithOwner();

    actingAs($owner)
        ->from('/settings/organization')
        ->delete('/settings/organization', ['name' => 'Acme'])
        ->assertRedirect('/settings/organization/create');

    expect(Organization::find($org->id))->toBeNull();
    expect(Organization::withTrashed()->find($org->id)->trashed())->toBeTrue();
    expect($owner->fresh()->organization_id)->toBeNull();
});

test('deleting an organization moves every member to personal mode and deactivates memberships', function () {
    [$org, $owner] = makeOrganizationWithOwner();

    $member = User::factory()->create(['organization_id' => $org->id]);
    $membership = OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $member->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    actingAs($owner)
        ->delete('/settings/organization', ['name' => 'Acme'])
        ->assertRedirect('/settings/organization/create');

    expect($member->fresh()->organization_id)->toBeNull();
    expect($membership->fresh()->status)->toBe(MembershipStatus::Inactive);
});

test('the organization name is free for immediate reuse after deletion', function () {
    [$org, $owner] = makeOrganizationWithOwner();

    actingAs($owner)->delete('/settings/organization', ['name' => 'Acme']);

    $reused = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

    expect($reused->id)->not->toBe($org->id);
    expect(Organization::where('name', 'Acme')->count())->toBe(1);
});

test('a non-owner member cannot delete the organization', function () {
    [$org] = makeOrganizationWithOwner();

    $member = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $member->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    actingAs($member)
        ->from('/settings/organization')
        ->delete('/settings/organization', ['name' => 'Acme'])
        ->assertRedirect('/settings/organization')
        ->assertSessionHasErrors('name');

    expect(Organization::find($org->id))->not->toBeNull();
});

test('a wrong confirmation name does not delete the organization', function () {
    [$org, $owner] = makeOrganizationWithOwner();

    actingAs($owner)
        ->from('/settings/organization')
        ->delete('/settings/organization', ['name' => 'Wrong Name'])
        ->assertRedirect('/settings/organization')
        ->assertSessionHasErrors('name');

    expect(Organization::find($org->id))->not->toBeNull();
    expect($owner->fresh()->organization_id)->toBe($org->id);
});
