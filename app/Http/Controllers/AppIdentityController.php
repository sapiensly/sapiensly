<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\DeviceCredential;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\IdentityConfirmation;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enrolling a device, and handing out the challenge every confirmation needs.
 *
 * The public key arrives already encoded as SubjectPublicKeyInfo — the browser
 * does that through `getPublicKey()` — so nothing on this side parses CBOR or
 * inspects an attestation. What is deliberately NOT verified here is the
 * attestation statement: it answers "which manufacturer made this
 * authenticator", which is a question about hardware procurement, not about
 * whether the person approving a refund is the person who enrolled. Every
 * check that matters happens at CONFIRMATION time against a challenge this
 * server minted.
 */
class AppIdentityController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
        private readonly AppAccessResolver $accessResolver,
        private readonly IdentityConfirmation $identity,
    ) {}

    /** A fresh challenge, plus what this person has already enrolled here. */
    public function challenge(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolve($request, $appSlug);

        return response()->json($this->identity->challengeFor($app, $request->user()));
    }

    public function store(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolve($request, $appSlug);

        $data = $request->validate([
            'id' => ['required', 'string', 'max:500'],
            // base64 of the SPKI the browser handed over.
            'public_key' => ['required', 'string', 'max:2000'],
            // base64url of clientDataJSON — plain JSON, no CBOR, and the only
            // thing that ties this enrolment to a challenge we minted.
            'client_data' => ['required', 'string', 'max:4000'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        // Spent here too. Without it a captured registration could be replayed
        // later to attach an attacker's key to this account, and every
        // confirmation after that would pass honestly.
        if (! $this->identity->spendForRegistration(
            $app,
            $request->user(),
            $data['client_data'],
            $request->getSchemeAndHttpHost(),
        )) {
            abort(422, 'That enrolment could not be verified. Try again.');
        }

        DeviceCredential::updateOrCreate(
            [
                'app_id' => $app->id,
                'credential_id' => $data['id'],
            ],
            [
                'user_id' => $request->user()->id,
                'public_key' => $data['public_key'],
                'label' => $data['label'] ?? null,
                'sign_count' => 0,
            ],
        );

        return response()->json(['ok' => true]);
    }

    private function resolve(Request $request, string $appSlug): App
    {
        $user = $request->user();

        $app = App::query()
            ->forAccountContext($user)
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$appSlug}' has no published manifest.");
        }

        if (! $this->accessResolver->resolve($app, $manifest, $user)->hasAccess) {
            abort(403, 'You do not have access to this app.');
        }

        return $app;
    }
}
