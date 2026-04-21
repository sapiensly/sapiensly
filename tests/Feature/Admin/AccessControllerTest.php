<?php

use App\Models\AppSetting;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'sysadmin', 'guard_name' => 'web']);
});

function makeSysadminForAccess(): User
{
    $user = User::factory()->create();
    $user->assignRole('sysadmin');

    return $user;
}

test('index renders Access with defaults when nothing is stored', function () {
    $sysadmin = makeSysadminForAccess();

    $this->actingAs($sysadmin)
        ->get('/admin/access')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Access')
            ->where('settings.registrationOpen', true)
            ->where('settings.twoFactorRequired', false)
            ->where('settings.domainAllowlist', [])
            ->where('settings.ipAllowlistEnabled', false)
            ->where('settings.sessionLifetimeMinutes', 120)
            ->has('posture', 3));
});

test('update persists a single boolean key', function () {
    $sysadmin = makeSysadminForAccess();

    $this->actingAs($sysadmin)
        ->patch('/admin/access', ['twoFactorRequired' => true])
        ->assertRedirect();

    expect(AppSetting::getBool('access.two_factor_required'))->toBeTrue();
});

test('registrationOpen update syncs the legacy key', function () {
    $sysadmin = makeSysadminForAccess();

    $this->actingAs($sysadmin)
        ->patch('/admin/access', ['registrationOpen' => false])
        ->assertRedirect();

    expect(AppSetting::getValue('registration_enabled'))->toBe('false');
    expect(AppSetting::getBool('access.registration_open'))->toBeFalse();
});

test('session lifetime rejects out-of-range values', function () {
    $sysadmin = makeSysadminForAccess();

    $this->actingAs($sysadmin)
        ->patch('/admin/access', ['sessionLifetimeMinutes' => 3])
        ->assertSessionHasErrors(['sessionLifetimeMinutes']);

    $this->actingAs($sysadmin)
        ->patch('/admin/access', ['sessionLifetimeMinutes' => 20000])
        ->assertSessionHasErrors(['sessionLifetimeMinutes']);

    $this->actingAs($sysadmin)
        ->patch('/admin/access', ['sessionLifetimeMinutes' => 60])
        ->assertRedirect();

    expect(AppSetting::getInt('access.session_lifetime_minutes', 0))->toBe(60);
});

test('domain allowlist rejects malformed domains', function () {
    $sysadmin = makeSysadminForAccess();

    $this->actingAs($sysadmin)
        ->patch('/admin/access', ['domainAllowlist' => ['not a domain']])
        ->assertSessionHasErrors();
});

test('domain allowlist stores a deduped, lowercased list', function () {
    $sysadmin = makeSysadminForAccess();

    $this->actingAs($sysadmin)
        ->patch('/admin/access', [
            'domainAllowlist' => ['ACME.com', 'acme.com', 'contractor.io'],
        ])
        ->assertRedirect();

    expect(AppSetting::getStringList('access.domain_allowlist'))
        ->toBe(['acme.com', 'contractor.io']);
});

test('usersWithoutTwoFactor returns users missing 2FA confirmation', function () {
    $sysadmin = makeSysadminForAccess();
    User::factory()->create(['two_factor_confirmed_at' => now()]);
    User::factory()->create();
    User::factory()->create();

    $response = $this->actingAs($sysadmin)
        ->getJson('/admin/access/users-without-2fa')
        ->assertOk();

    // sysadmin + 2 others created without 2FA = 3
    expect($response->json('count'))->toBe(3)
        ->and($response->json('users'))->toHaveCount(3);
});

test('non-sysadmin is blocked from Access endpoints', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get('/admin/access')->assertForbidden();
    $this->actingAs($member)
        ->patch('/admin/access', ['twoFactorRequired' => true])
        ->assertForbidden();
});
