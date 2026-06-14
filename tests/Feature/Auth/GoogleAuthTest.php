<?php

use App\Models\AppSetting;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;

function fakeGoogleUser(array $overrides = []): void
{
    $user = (new SocialiteUser)->map(array_merge([
        'id' => 'google-123',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'avatar' => 'https://example.com/a.png',
    ], $overrides));

    Socialite::fake('google', $user);
}

test('the redirect route sends the user to the provider', function () {
    Socialite::fake('google');

    $this->get('/auth/google/redirect')->assertRedirect();
});

test('callback provisions a new personal account and signs in', function () {
    fakeGoogleUser();

    $this->get('/auth/google/callback')->assertRedirect('/chat');

    $user = User::where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->google_id)->toBe('google-123')
        ->and($user->organization_id)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();
    assertAuthenticated();
});

test('callback links an existing account by email', function () {
    $existing = User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);
    fakeGoogleUser();

    $this->get('/auth/google/callback')->assertRedirect('/chat');

    expect($existing->fresh()->google_id)->toBe('google-123');
    assertAuthenticated();
    expect(auth()->id())->toBe($existing->id);
});

test('a blocked user cannot sign in with Google', function () {
    User::factory()->create(['email' => 'jane@example.com', 'blocked_at' => now()]);
    fakeGoogleUser();

    $this->get('/auth/google/callback')->assertRedirect('/login');
    assertGuest();
});

test('a new account is refused when registration is disabled', function () {
    AppSetting::setValue('registration_enabled', 'false');
    fakeGoogleUser();

    $this->get('/auth/google/callback')->assertRedirect('/login');

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
    assertGuest();
});
