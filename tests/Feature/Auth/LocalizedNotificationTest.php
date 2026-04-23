<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\App;

// Locale preference drives the notification language at render time —
// Laravel reads `preferredLocale()` from the notifiable and wraps the
// channel send in `withLocale()`, so queued or sync mail both pick it up.

test('User exposes preferredLocale from the locale column', function () {
    $user = User::factory()->create(['locale' => 'es']);

    expect($user->preferredLocale())->toBe('es');
});

test('User falls back to the app locale when locale is unset', function () {
    $user = User::factory()->create();
    // Column has a DB default of 'en'; unset() simulates a legacy row
    // that was never assigned a locale at all.
    $user->setRawAttributes(array_merge($user->getAttributes(), ['locale' => null]));

    expect($user->preferredLocale())->toBe(config('app.fallback_locale'));
});

test('VerifyEmail notification renders in Spanish under the es locale', function () {
    $user = User::factory()->create(['locale' => 'es']);

    App::setLocale($user->preferredLocale());
    $message = (new VerifyEmail)->toMail($user);

    expect($message->subject)->toBe('Verifica tu correo electrónico');
    expect($message->actionText)->toBe('Verificar correo');
    expect(implode(' ', $message->introLines))
        ->toContain('Haz clic en el botón de abajo');
});

test('VerifyEmail notification renders in English under the en locale', function () {
    $user = User::factory()->create(['locale' => 'en']);

    App::setLocale($user->preferredLocale());
    $message = (new VerifyEmail)->toMail($user);

    expect($message->subject)->toBe('Verify your email address');
    expect($message->actionText)->toBe('Verify Email Address');
});

test('ResetPassword notification renders in Spanish under the es locale', function () {
    $user = User::factory()->create(['locale' => 'es']);

    App::setLocale($user->preferredLocale());
    $message = (new ResetPassword('fake-token'))->toMail($user);

    expect($message->subject)->toBe('Restablece tu contraseña');
    expect($message->actionText)->toBe('Restablecer contraseña');
    expect(implode(' ', $message->introLines))
        ->toContain('Recibes este correo');
});
