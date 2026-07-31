<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Platform\InspectPlatformUserTool;
use App\Mcp\Tools\Platform\InvitePlatformUserTool;
use App\Mcp\Tools\Platform\ListPlatformUsersTool;
use App\Mcp\Tools\Platform\ManageOrganizationMembershipTool;
use App\Mcp\Tools\Platform\ManagePlatformUserTool;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
    $this->sysadmin = mcpSysadmin($this->org);
    $this->member = mcpMember($this->org, MembershipRole::Member);
    mcpActingContext(['platform:admin']);
});

it('lists accounts across organizations with status filters', function () {
    $this->member->update(['blocked_at' => now()]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListPlatformUsersTool::class, ['status' => 'blocked'])
        ->assertOk()
        ->assertSee($this->member->email);
});

it('inspects an account and flags the organizations it solely owns', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(InspectPlatformUserTool::class, ['user' => $this->sysadmin->email])
        ->assertOk()
        ->assertSee('sole_owner_of')
        ->assertSee($this->org->name);
});

it('invites an account without ever setting a password', function () {
    Notification::fake();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(InvitePlatformUserTool::class, [
            'email' => 'newcomer@example.com',
            'organization' => $this->org->slug,
            'membership_role' => 'member',
        ])
        ->assertOk()
        ->assertSee('newcomer@example.com');

    $invited = User::whereRaw('lower(email) = ?', ['newcomer@example.com'])->first();

    expect($invited)->not->toBeNull()
        ->and($invited->hasRole('member'))->toBeTrue()
        ->and(OrganizationMembership::where('user_id', $invited->id)->exists())->toBeTrue();
});

it('refuses to invite an address that already has an account', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(InvitePlatformUserTool::class, ['email' => $this->member->email])
        ->assertHasErrors();
});

it('blocks and unblocks an account', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManagePlatformUserTool::class, ['user' => $this->member->email, 'action' => 'block'])
        ->assertOk();

    expect($this->member->refresh()->isBlocked())->toBeTrue();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManagePlatformUserTool::class, ['user' => $this->member->email, 'action' => 'unblock'])
        ->assertOk();

    expect($this->member->refresh()->isBlocked())->toBeFalse();
    expect(PlatformAuditLog::where('action', 'manage_platform_user')->count())->toBe(2);
});

it('refuses to act on your own account', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManagePlatformUserTool::class, ['user' => $this->sysadmin->email, 'action' => 'block'])
        ->assertHasErrors();

    expect($this->sysadmin->refresh()->isBlocked())->toBeFalse();
});

it('will not delete an account without the typed confirmation', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManagePlatformUserTool::class, ['user' => $this->member->email, 'action' => 'delete'])
        ->assertHasErrors();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManagePlatformUserTool::class, [
            'user' => $this->member->email,
            'action' => 'delete',
            'confirm_email' => 'wrong@example.com',
        ])
        ->assertHasErrors();

    expect(User::find($this->member->id))->not->toBeNull();
});

it('deletes an account once the address is confirmed', function () {
    $email = $this->member->email;

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManagePlatformUserTool::class, [
            'user' => $email,
            'action' => 'delete',
            'confirm_email' => $email,
        ])
        ->assertOk();

    expect(User::find($this->member->id))->toBeNull()
        // The audit row outlives its subject — that is the point of storing
        // the actor and target denormalized.
        ->and(PlatformAuditLog::where('target_label', $email)->exists())->toBeTrue();
});

it('adds a member to an organization and changes their role', function () {
    $outsider = mcpMember(mcpOrg('Elsewhere'));

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageOrganizationMembershipTool::class, [
            'organization' => $this->org->slug,
            'user' => $outsider->email,
            'action' => 'add',
            'role' => 'member',
        ])
        ->assertOk();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageOrganizationMembershipTool::class, [
            'organization' => $this->org->slug,
            'user' => $outsider->email,
            'action' => 'set_role',
            'role' => 'owner',
        ])
        ->assertOk();

    $membership = OrganizationMembership::where('organization_id', $this->org->id)
        ->where('user_id', $outsider->id)
        ->first();

    expect($membership->role)->toBe(MembershipRole::Owner)
        ->and($membership->status)->toBe(MembershipStatus::Active);
});

it('never leaves an organization without an active owner', function () {
    // The sysadmin is its only owner; each of these would strand the tenant.
    foreach ([
        ['action' => 'set_role', 'role' => 'member'],
        ['action' => 'deactivate'],
        ['action' => 'remove'],
    ] as $attempt) {
        SapiensServer::actingAs($this->sysadmin)
            ->tool(ManageOrganizationMembershipTool::class, array_merge([
                'organization' => $this->org->slug,
                'user' => $this->sysadmin->email,
            ], $attempt))
            ->assertHasErrors();
    }

    $membership = OrganizationMembership::where('organization_id', $this->org->id)
        ->where('user_id', $this->sysadmin->id)
        ->first();

    expect($membership->role)->toBe(MembershipRole::Owner)
        ->and($membership->status)->toBe(MembershipStatus::Active);
});

it('allows demoting an owner once another active owner exists', function () {
    $second = mcpMember($this->org, MembershipRole::Owner);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageOrganizationMembershipTool::class, [
            'organization' => $this->org->slug,
            'user' => $second->email,
            'action' => 'set_role',
            'role' => 'member',
        ])
        ->assertOk();

    expect(OrganizationMembership::where('user_id', $second->id)->first()->role)
        ->toBe(MembershipRole::Member);
});
