<?php

namespace App\Services\Apps;

use App\Facades\TenantCache;
use App\Mail\AppNotificationMail;
use App\Models\App;
use App\Models\PortalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sign-in for a portal, by emailed magic link.
 *
 * No passwords, on purpose. A portal's users are the public — customers,
 * applicants, suppliers — and asking a platform to hold credentials for people
 * who visit twice a year buys a hashing scheme, a reset flow, a lockout policy
 * and a breach surface, in exchange for nothing the link does not already do.
 * Proving you can read an address is the whole of what the portal needs.
 *
 * What the link is: a random token, stored only as a SHA-256, single-use, and
 * short-lived. Exactly one is pending per person, so asking for a new one
 * invalidates the last — which is both the behaviour people expect and one
 * fewer place for a live credential to sit.
 *
 * The session key is per APP. A portal user signed into one portal is a
 * stranger at every other, including another portal of the same organization,
 * and nothing about being signed in here reaches the platform's own auth.
 */
class PortalAuth
{
    /** Requests to the same address before we stop sending. */
    private const MAX_LINKS_PER_HOUR = 5;

    /**
     * Start a sign-in. Returns nothing about whether the address is known:
     * a portal that answers differently for a registered address is an account
     * oracle, and the whole point is that anyone may ask.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws RuntimeException when the portal has no sign-in at all
     */
    public function requestLink(App $app, array $manifest, string $email, string $linkBase): void
    {
        $mode = (string) ($manifest['permissions']['public']['signup'] ?? 'none');
        if ($mode === 'none') {
            throw new RuntimeException('This page does not have sign-in.');
        }

        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('That does not look like an email address.');
        }

        $portalUser = PortalUser::query()
            ->where('app_id', $app->id)
            ->where('email', $email)
            ->first();

        // Invite-only: an address nobody added gets the same silence a typo
        // gets. Telling a stranger "you were not invited" confirms the portal
        // has a guest list and that they are not on it.
        if ($portalUser === null && $mode === 'invite') {
            return;
        }

        if ($portalUser?->isBlocked()) {
            return;
        }

        $portalUser ??= new PortalUser([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'email' => $email,
            'status' => 'active',
        ]);

        if ($this->tooManyRequests($app, $portalUser)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $portalUser->forceFill([
            'login_token_hash' => hash('sha256', $token),
            'login_token_expires_at' => now()->addMinutes(PortalUser::LINK_TTL_MINUTES),
        ])->save();

        $link = rtrim($linkBase, '/').'/auth/'.$token;

        Mail::to($email)->send(new AppNotificationMail(
            $app,
            'Tu acceso a '.$app->name,
            'Entra con este enlace. Caduca en '.PortalUser::LINK_TTL_MINUTES." minutos y sólo funciona una vez.\n\nSi no lo pediste, ignora este correo.",
            $link,
        ));
    }

    /**
     * Consume a link and establish the session. Returns the person, or null for
     * anything that does not resolve — expired, already used, blocked, or
     * simply wrong. One outcome for all of them: distinguishing them tells an
     * attacker which of their guesses was close.
     */
    public function consume(Request $request, App $app, string $token): ?PortalUser
    {
        $portalUser = PortalUser::query()
            ->where('app_id', $app->id)
            ->where('login_token_hash', hash('sha256', $token))
            ->first();

        if ($portalUser === null
            || $portalUser->isBlocked()
            || $portalUser->login_token_expires_at === null
            || $portalUser->login_token_expires_at->isPast()) {
            return null;
        }

        // Single use: burn it before the session exists, so a replay of the
        // same URL finds nothing even if the response never reached the browser.
        $portalUser->forceFill([
            'login_token_hash' => null,
            'login_token_expires_at' => null,
            'status' => 'active',
            'last_login_at' => now(),
        ])->save();

        // A fresh session id on privilege change — the standard defence against
        // an attacker who set the visitor's session cookie beforehand.
        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($app), $portalUser->id);

        return $portalUser;
    }

    /** The signed-in portal user for this app, or null. */
    public function current(Request $request, App $app): ?PortalUser
    {
        $id = $request->session()->get($this->sessionKey($app));
        if (! is_string($id) || $id === '') {
            return null;
        }

        $portalUser = PortalUser::query()
            ->where('app_id', $app->id)
            ->where('id', $id)
            ->first();

        // A session outliving the identity it names — deleted, or blocked since
        // signing in — resolves to nobody rather than to a stale grant.
        return ($portalUser === null || $portalUser->isBlocked()) ? null : $portalUser;
    }

    public function logout(Request $request, App $app): void
    {
        $request->session()->forget($this->sessionKey($app));
    }

    /**
     * Per-app, so being signed into one portal means nothing at another — not
     * even at a sibling portal of the same organization.
     */
    private function sessionKey(App $app): string
    {
        return 'portal_user.'.$app->id;
    }

    /**
     * Bound how often one address can be mailed. Without this, the form is a
     * free way to send mail from the tenant's domain to anyone, repeatedly.
     */
    private function tooManyRequests(App $app, PortalUser $portalUser): bool
    {
        if (! $portalUser->exists) {
            return false;
        }

        // Scoped to the app's owner explicitly rather than to the ambient
        // request: this counter is tenant-derived, and Redis has no structural
        // isolation of its own — a shared key here would count one tenant's
        // visitors against another's.
        $cache = TenantCache::forOwner($app->organization_id, $app->user_id);
        $key = 'portal_link:'.$portalUser->id;

        $sent = (int) $cache->get($key, 0);
        if ($sent >= self::MAX_LINKS_PER_HOUR) {
            return true;
        }
        $cache->put($key, $sent + 1, 3600);

        return false;
    }
}
