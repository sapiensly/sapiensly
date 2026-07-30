<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\PortalAuth;
use App\Support\Http\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign-in for a portal: ask for a link, follow it, sign out.
 *
 * The request endpoint answers the SAME way whatever happened — address known
 * or not, invited or not, rate-limited or not. A portal that replies
 * differently for a registered address is an account oracle, and anyone may
 * ask, so there is nothing to distinguish for.
 */
class PortalAuthController extends Controller
{
    public function __construct(
        private readonly PortalAuth $auth,
    ) {}

    public function request(Request $request, string $publicSlug): JsonResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');
        /** @var array<string, mixed> $manifest */
        $manifest = $request->attributes->get('publicAppManifest');

        $data = $request->validate([
            'email' => ['required', 'string', 'max:320'],
            // The honeypot: humans never see it, bots love it.
            'website' => ['sometimes', 'nullable', 'string'],
            'turnstile_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $sent = ['ok' => true, 'message' => 'Si esa dirección puede entrar, te enviamos un enlace.'];

        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse($sent);
        }
        if (! Turnstile::passes($request, (string) ($data['turnstile_token'] ?? ''))) {
            return new JsonResponse($sent);
        }

        try {
            $this->auth->requestLink(
                $app,
                $manifest,
                $data['email'],
                url('/a/'.$app->public_slug),
            );
        } catch (\RuntimeException) {
            // Including "this portal has no sign-in": still the same answer, so
            // the endpoint never maps a portal's configuration for a prober.
        }

        return new JsonResponse($sent);
    }

    /**
     * Follow a magic link. Success and failure both land on the portal's front
     * page — a dedicated error page would tell someone holding a stale link
     * exactly which of expired / used / wrong they are holding.
     */
    public function callback(Request $request, string $publicSlug, string $token): RedirectResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');

        $portalUser = $this->auth->consume($request, $app, $token);

        return redirect('/a/'.$app->public_slug)
            ->with($portalUser !== null ? 'success' : 'error', $portalUser !== null
                ? 'Sesión iniciada.'
                : 'Ese enlace ya no sirve. Pide uno nuevo.');
    }

    public function logout(Request $request, string $publicSlug): RedirectResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');

        $this->auth->logout($request, $app);

        return redirect('/a/'.$app->public_slug);
    }
}
