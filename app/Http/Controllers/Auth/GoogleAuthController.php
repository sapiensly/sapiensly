<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * "Sign in with Google" for personal accounts. Links an existing user by
 * google_id or email, or provisions a new organization-less user. New-account
 * creation honours the same registration switch Fortify respects; existing
 * users may always sign in.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'email' => __('Google sign-in failed. Please try again.'),
            ]);
        }

        $email = (string) $googleUser->getEmail();
        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'email' => __('Google did not return an email address for this account.'),
            ]);
        }

        $user = User::query()->where('google_id', $googleUser->getId())->first()
            ?? User::query()->where('email', $email)->first();

        if ($user === null) {
            if (! AppSetting::isRegistrationEnabled()) {
                return redirect()->route('login')->withErrors([
                    'email' => __('Registration is currently disabled.'),
                ]);
            }

            $user = new User;
            $user->email = $email;
            $user->password = null;
            $user->organization_id = null;
        }

        if ($user->isBlocked()) {
            return redirect()->route('login')->withErrors([
                'email' => __('Your account has been blocked. Please contact support.'),
            ]);
        }

        $user->google_id = $googleUser->getId();
        if (! $user->name) {
            $user->name = (string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $email);
        }
        if (! $user->avatar && $googleUser->getAvatar()) {
            $user->avatar = $googleUser->getAvatar();
        }
        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }
        $user->save();

        Auth::login($user, remember: true);

        return redirect()->intended(route('chat.index'));
    }
}
