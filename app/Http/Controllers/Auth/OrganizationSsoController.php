<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationSsoConnection;
use App\Models\User;
use App\Services\OrganizationService;
use App\Services\Sso\OidcLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Per-organization enterprise OIDC SSO login. Members reach an org's dedicated
 * URL (/sso/{slug}), authenticate at the org's IdP, and are matched or
 * provisioned into the organization. Configuration is owner-only and lives in
 * Settings; this controller is the guest-facing login leg.
 */
class OrganizationSsoController extends Controller
{
    private const SESSION_KEY = 'sso.oidc.state';

    public function __construct(
        private OidcLoginService $oidc,
        private OrganizationService $organizations,
    ) {}

    public function redirect(Request $request, string $slug): RedirectResponse
    {
        $organization = Organization::query()->where('slug', $slug)->first();
        $connection = $organization?->ssoConnection;

        if (! $connection instanceof OrganizationSsoConnection || ! $connection->enabled) {
            return redirect()->route('login')->withErrors([
                'email' => __('Single sign-on is not enabled for this organization.'),
            ]);
        }

        $prepared = $this->oidc->buildLoginRedirect($connection, route('sso.callback'));

        $request->session()->put(self::SESSION_KEY, [
            'connection_id' => $connection->id,
            'organization_id' => $organization->id,
            'state' => $prepared['state'],
            'nonce' => $prepared['nonce'],
            'code_verifier' => $prepared['code_verifier'],
        ]);

        return redirect()->away($prepared['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        if (! is_array($stored) || empty($stored['connection_id'])) {
            abort(400, __('No pending single sign-on handshake in this session.'));
        }

        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect()->route('login')->withErrors([
                'email' => __('The identity provider returned an error: :error', ['error' => $error]),
            ]);
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '') {
            abort(400, __('Missing code or state parameter.'));
        }

        if (! hash_equals((string) ($stored['state'] ?? ''), $state)) {
            abort(400, __('Single sign-on state mismatch — possible CSRF.'));
        }

        $connection = OrganizationSsoConnection::query()->find($stored['connection_id']);
        if (! $connection instanceof OrganizationSsoConnection || ! $connection->enabled) {
            return redirect()->route('login')->withErrors([
                'email' => __('Single sign-on is not enabled for this organization.'),
            ]);
        }

        $identity = $this->oidc->resolveIdentity(
            $connection,
            route('sso.callback'),
            $code,
            $stored['code_verifier'] ?? null,
        );

        if (! $connection->permitsEmail($identity['email'])) {
            return redirect()->route('login')->withErrors([
                'email' => __('Your email domain is not permitted to sign in to this organization.'),
            ]);
        }

        $user = $this->resolveUser($connection, $identity);
        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'email' => __('No account exists for this organization and automatic provisioning is disabled.'),
            ]);
        }

        if ($user->isBlocked()) {
            return redirect()->route('login')->withErrors([
                'email' => __('Your account has been blocked. Please contact support.'),
            ]);
        }

        Auth::login($user, remember: true);
        $this->organizations->switchAccount($user, $connection->organization_id);

        return redirect()->intended(route('chat.index'));
    }

    /**
     * Match an existing user by email or provision a new member when the
     * connection allows it. On success the returned user is guaranteed to hold
     * an *active* membership in the organization, so the active-account switch
     * cannot fail. Returns null when sign-in should be refused (the user or an
     * active membership is missing and auto-provisioning is disabled).
     *
     * @param  array{email: string, sub: string, name: ?string, email_verified: bool}  $identity
     */
    private function resolveUser(OrganizationSsoConnection $connection, array $identity): ?User
    {
        $user = User::query()->where('email', $identity['email'])->first();

        $membership = $user === null ? null : OrganizationMembership::query()
            ->where('organization_id', $connection->organization_id)
            ->where('user_id', $user->id)
            ->first();

        // An existing active member always signs in; no mutation needed.
        if ($membership !== null && $membership->isActive()) {
            return $user;
        }

        // Otherwise we must create or reactivate — only the connection's
        // auto-provision setting authorizes that.
        if (! $connection->auto_provision) {
            return null;
        }

        return DB::transaction(function () use ($connection, $identity, $user, $membership) {
            if ($user === null) {
                $user = new User;
                $user->email = $identity['email'];
                $user->name = $identity['name'] ?: $identity['email'];
                $user->password = null;
                $user->email_verified_at = now();
                $user->save();
            } elseif ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if ($membership === null) {
                OrganizationMembership::query()->create([
                    'organization_id' => $connection->organization_id,
                    'user_id' => $user->id,
                    'role' => MembershipRole::Member,
                    'status' => MembershipStatus::Active,
                ]);
            } else {
                // Reactivate without touching the role — an inactive Owner
                // returning via SSO stays an Owner.
                $membership->update(['status' => MembershipStatus::Active]);
            }

            return $user;
        });
    }
}
