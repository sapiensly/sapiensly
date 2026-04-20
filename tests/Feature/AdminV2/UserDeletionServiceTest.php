<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Admin\UserDeletionService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Membership observer syncs Spatie roles matching MembershipRole::value.
    foreach (['owner', 'admin', 'member'] as $name) {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Permission::firstOrCreate(['name' => 'documents.view', 'guard_name' => 'web']);
});

function makeOrgWithOwnerForDeletion(User $owner): Organization
{
    $org = Organization::create(['name' => 'Acme Co', 'slug' => null]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);
    $owner->update(['organization_id' => $org->id]);

    return $org;
}

test('delete transfers owned resources to the organization owner', function () {
    $owner = User::factory()->create();
    $org = makeOrgWithOwnerForDeletion($owner);

    $target = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $target->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    // The target owns one document.
    $doc = Document::create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'name' => 'Target\'s doc',
        'type' => 'txt',
        'body' => 'hello',
        'visibility' => 'private',
    ]);

    $branch = app(UserDeletionService::class)->delete($target);

    expect($branch)->toBe('transferred');
    expect(User::find($target->id))->toBeNull();
    expect($doc->fresh()->user_id)->toBe($owner->id);
    expect(OrganizationMembership::where('user_id', $target->id)->count())->toBe(0);
});

test('delete cascades owned resources when the user has no organization', function () {
    $target = User::factory()->create(['organization_id' => null]);

    Document::create([
        'user_id' => $target->id,
        'organization_id' => null,
        'name' => 'Orphan doc',
        'type' => 'txt',
        'body' => 'hi',
        'visibility' => 'private',
    ]);

    $branch = app(UserDeletionService::class)->delete($target);

    expect($branch)->toBe('cascaded');
    expect(User::find($target->id))->toBeNull();
    expect(Document::where('user_id', $target->id)->withTrashed()->count())->toBe(0);
});

test('delete cascades when the user is the only owner of their organization', function () {
    $target = User::factory()->create();
    $org = makeOrgWithOwnerForDeletion($target);

    Document::create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'name' => 'Last-owner doc',
        'type' => 'txt',
        'body' => 'bye',
        'visibility' => 'private',
    ]);

    $branch = app(UserDeletionService::class)->delete($target);

    // No surviving owner to transfer to — falls through to cascade.
    expect($branch)->toBe('cascaded');
    expect(User::find($target->id))->toBeNull();
});
