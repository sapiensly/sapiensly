<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'sysadmin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);
});

function makeSysadmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('sysadmin');

    return $user;
}

test('index renders the users page with transformed data', function () {
    $sysadmin = makeSysadmin();
    User::factory()->count(3)->create();

    $this->actingAs($sysadmin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Users/Index')
            ->has('users.data', 4)  // 3 + sysadmin
            ->where('users.meta.total', 4));
});

test('index filters by search query', function () {
    $sysadmin = makeSysadmin();
    User::factory()->create(['name' => 'Alice Needle']);
    User::factory()->create(['name' => 'Bob Haystack']);

    $this->actingAs($sysadmin)
        ->get('/admin/users?q=needle')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Alice Needle'));
});

test('index filters by status unverified', function () {
    $sysadmin = makeSysadmin();
    User::factory()->unverified()->create();
    User::factory()->create();

    $this->actingAs($sysadmin)
        ->get('/admin/users?status=unverified')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 1));
});

test('invite creates user, sends verification, assigns role', function () {
    Notification::fake();
    $sysadmin = makeSysadmin();

    $this->actingAs($sysadmin)
        ->post('/admin/users/invite', [
            'email' => 'new@example.com',
            'name' => 'New Hire',
            'role' => 'admin',
        ])
        ->assertRedirect();

    $user = User::where('email', 'new@example.com')->firstOrFail();
    expect($user->hasRole('admin'))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('invite rejects duplicate emails', function () {
    $sysadmin = makeSysadmin();
    User::factory()->create(['email' => 'dup@example.com']);

    $this->actingAs($sysadmin)
        ->post('/admin/users/invite', [
            'email' => 'dup@example.com',
            'role' => 'member',
        ])
        ->assertSessionHasErrors(['email']);
});

test('block and unblock toggle blocked_at', function () {
    $sysadmin = makeSysadmin();
    $target = User::factory()->create();

    $this->actingAs($sysadmin)
        ->post("/admin/users/{$target->id}/block")
        ->assertRedirect();
    expect($target->fresh()->blocked_at)->not->toBeNull();

    $this->actingAs($sysadmin)
        ->post("/admin/users/{$target->id}/unblock")
        ->assertRedirect();
    expect($target->fresh()->blocked_at)->toBeNull();
});

test('block refuses self-blocking', function () {
    $sysadmin = makeSysadmin();

    $this->actingAs($sysadmin)
        ->post("/admin/users/{$sysadmin->id}/block")
        ->assertSessionHasErrors();
    expect($sysadmin->fresh()->blocked_at)->toBeNull();
});

test('resend verification notifies unverified users', function () {
    Notification::fake();
    $sysadmin = makeSysadmin();
    $target = User::factory()->unverified()->create();

    $this->actingAs($sysadmin)
        ->post("/admin/users/{$target->id}/resend-verification")
        ->assertRedirect();

    Notification::assertSentTo($target, VerifyEmail::class);
});

test('reset 2fa clears Fortify fields', function () {
    $sysadmin = makeSysadmin();
    $target = User::factory()->create([
        'two_factor_secret' => 'x',
        'two_factor_recovery_codes' => 'y',
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($sysadmin)
        ->post("/admin/users/{$target->id}/reset-2fa")
        ->assertRedirect();

    $fresh = $target->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
});

test('destroy requires email confirmation match', function () {
    $sysadmin = makeSysadmin();
    $target = User::factory()->create(['email' => 'goodbye@example.com']);

    $this->actingAs($sysadmin)
        ->delete("/admin/users/{$target->id}", [
            'email_confirmation' => 'typo@example.com',
        ])
        ->assertSessionHasErrors(['email_confirmation']);

    expect(User::find($target->id))->not->toBeNull();
});

test('destroy deletes user and redirects to index on match', function () {
    $sysadmin = makeSysadmin();
    $target = User::factory()->create(['email' => 'bye@example.com']);

    $this->actingAs($sysadmin)
        ->delete("/admin/users/{$target->id}", [
            'email_confirmation' => 'bye@example.com',
        ])
        ->assertRedirect('/admin/users');

    expect(User::find($target->id))->toBeNull();
});

test('non-sysadmin gets 403 on the users index', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get('/admin/users')
        ->assertForbidden();
});
