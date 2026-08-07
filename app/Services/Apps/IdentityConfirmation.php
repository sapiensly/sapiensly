<?php

namespace App\Services\Apps;

use App\Facades\TenantCache;
use App\Models\App;
use App\Models\DeviceCredential;
use App\Models\User;
use App\Support\Identity\WebAuthnAssertion;

/**
 * The gate a `require_identity` action stands behind.
 *
 * Two halves, and the CHALLENGE is what joins them: this server mints one,
 * remembers it for two minutes, and refuses any assertion that does not carry
 * it back. Without that a captured assertion would be a permanent key to
 * somebody else's refund button.
 *
 * Single use, deliberately. The challenge is deleted the moment it is spent, so
 * one fingerprint approves one action — not every action taken in the next two
 * minutes by whoever is holding the phone afterwards.
 */
class IdentityConfirmation
{
    /** Long enough to find a finger, short enough that a captured one is stale. */
    private const TTL_SECONDS = 120;

    /** @return array{challenge: string, credential_ids: list<string>} */
    public function challengeFor(App $app, User $user): array
    {
        $challenge = random_bytes(32);

        // Scoped through TenantCache like everything else derived from tenant
        // data: Redis is one shared keyspace, and a challenge keyed by user id
        // alone would collide across organizations.
        TenantCache::put($this->key($app, $user), $challenge, self::TTL_SECONDS);

        return [
            'challenge' => WebAuthnAssertion::encode($challenge),
            // What this person has already enrolled HERE. An empty list is how
            // the client knows to enrol rather than to ask.
            'credential_ids' => DeviceCredential::query()
                ->where('app_id', $app->id)
                ->where('user_id', $user->id)
                ->pluck('credential_id')
                ->all(),
        ];
    }

    /**
     * Spend a challenge on an ENROLMENT.
     *
     * There is no signature to check here — the key being registered is the one
     * that would sign it — so what is checked is that this browser really was
     * answering a challenge this server minted, at this origin. Without that, a
     * captured registration could be replayed later to attach an attacker's key
     * to the account, and every confirmation after that would pass honestly.
     */
    public function spendForRegistration(App $app, User $user, string $clientDataB64, string $origin): bool
    {
        $challenge = TenantCache::pull($this->key($app, $user));
        if (! is_string($challenge) || $challenge === '') {
            return false;
        }

        $clientData = json_decode(WebAuthnAssertion::decode($clientDataB64), true);
        if (! is_array($clientData)) {
            return false;
        }

        return ($clientData['type'] ?? '') === 'webauthn.create'
            && hash_equals($challenge, WebAuthnAssertion::decode((string) ($clientData['challenge'] ?? '')))
            && hash_equals($origin, (string) ($clientData['origin'] ?? ''));
    }

    /**
     * Spend a challenge, or fail.
     *
     * @param  array<string, mixed>  $assertion  as the browser produced it
     */
    public function confirm(App $app, User $user, array $assertion, string $origin, string $rpId): bool
    {
        $challenge = TenantCache::pull($this->key($app, $user));
        if (! is_string($challenge) || $challenge === '') {
            return false;
        }

        $credentialId = (string) ($assertion['id'] ?? '');

        $credential = DeviceCredential::query()
            ->where('app_id', $app->id)
            ->where('user_id', $user->id)
            ->where('credential_id', $credentialId)
            ->first();

        if ($credential === null) {
            return false;
        }

        $authenticatorData = WebAuthnAssertion::decode((string) ($assertion['authenticator_data'] ?? ''));

        $ok = WebAuthnAssertion::verify(
            base64_decode((string) $credential->public_key, true) ?: '',
            $authenticatorData,
            WebAuthnAssertion::decode((string) ($assertion['client_data'] ?? '')),
            WebAuthnAssertion::decode((string) ($assertion['signature'] ?? '')),
            $challenge,
            $origin,
            $rpId,
        );

        if (! $ok) {
            return false;
        }

        // A counter that goes BACKWARDS means two things are answering with one
        // credential. Most platform authenticators always report zero and are
        // exempt by construction, so only a real regression is refused.
        $count = WebAuthnAssertion::signCount($authenticatorData);
        if ($count > 0 && $count <= (int) $credential->sign_count) {
            return false;
        }

        $credential->update([
            'sign_count' => max($count, (int) $credential->sign_count),
            'last_used_at' => now(),
        ]);

        return true;
    }

    private function key(App $app, User $user): string
    {
        return "identity:challenge:{$app->id}:{$user->id}";
    }
}
