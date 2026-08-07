<?php

namespace App\Support\Identity;

/**
 * Checking that the person at the device is the one who registered it.
 *
 * For the action somebody should have to mean: a refund, a write-off, a
 * price override, a deletion. A confirm dialog asks whether you MEANT it; this
 * asks WHO you are, with the fingerprint or the face the device already knows —
 * which is the only kind of second factor a technician holding a phone in the
 * rain will actually use.
 *
 * Verified on the SERVER and nowhere else. A browser-side check that a
 * fingerprint was accepted is worth nothing: the thing sending the request is
 * the thing that would be lying. What makes this real is that the signature is
 * over a challenge this server minted and an origin it names, checked here
 * against a public key registered earlier.
 *
 * Registration parses no CBOR: the browser hands the public key over already
 * encoded as SubjectPublicKeyInfo through `getPublicKey()`, which is exactly
 * what openssl reads. That is why this is a hundred lines and not a library.
 */
final class WebAuthnAssertion
{
    /** The authenticator says a human was present. */
    private const FLAG_USER_PRESENT = 0x01;

    /** …and that it verified WHO they are: a fingerprint, a face, a PIN. */
    private const FLAG_USER_VERIFIED = 0x04;

    /**
     * Whether this assertion really was made by that key, for that challenge.
     *
     * Every argument is raw bytes except the base64url the browser sends, and
     * every check below has a reason to exist:
     *
     * - the SIGNATURE, or somebody could send any bytes they liked;
     * - the CHALLENGE, or a captured assertion could be replayed for ever;
     * - the ORIGIN and the RP ID, or a phishing page on another domain could
     *   collect an assertion and present it here;
     * - USER VERIFIED, because "somebody touched the sensor" is not the claim
     *   being made — a device with the screen unlocked in a shared van would
     *   otherwise satisfy an approval it was never meant to.
     *
     * @param  string  $publicKeySpki  the registered key, DER SubjectPublicKeyInfo
     */
    public static function verify(
        string $publicKeySpki,
        string $authenticatorData,
        string $clientDataJson,
        string $signature,
        string $expectedChallenge,
        string $expectedOrigin,
        string $expectedRpId,
    ): bool {
        if (strlen($authenticatorData) < 37) {
            return false;
        }

        $clientData = json_decode($clientDataJson, true);
        if (! is_array($clientData)) {
            return false;
        }

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            return false;
        }

        // Compared as decoded BYTES: the browser's base64url is unpadded and a
        // string comparison against a padded challenge fails for no reason.
        if (! hash_equals(
            $expectedChallenge,
            self::decode((string) ($clientData['challenge'] ?? '')),
        )) {
            return false;
        }

        if (! hash_equals($expectedOrigin, (string) ($clientData['origin'] ?? ''))) {
            return false;
        }

        if (! hash_equals(
            hash('sha256', $expectedRpId, true),
            substr($authenticatorData, 0, 32),
        )) {
            return false;
        }

        $flags = ord($authenticatorData[32]);
        if (($flags & self::FLAG_USER_PRESENT) === 0) {
            return false;
        }

        if (($flags & self::FLAG_USER_VERIFIED) === 0) {
            return false;
        }

        $key = openssl_pkey_get_public(self::pem($publicKeySpki));
        if ($key === false) {
            return false;
        }

        // What the authenticator signed, in the order the spec fixes.
        $signed = $authenticatorData.hash('sha256', $clientDataJson, true);

        // The signature is DER here, unlike VAPID's raw pair — openssl reads it
        // as it stands.
        return openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The counter an authenticator reports, when it reports one.
     *
     * A value that goes BACKWARDS means two things are answering with the same
     * credential — a cloned key. Most platform authenticators (Touch ID, a
     * phone's own) always report 0 and are exempt by construction, which is why
     * this is read out here and judged by the caller rather than enforced.
     */
    public static function signCount(string $authenticatorData): int
    {
        if (strlen($authenticatorData) < 37) {
            return 0;
        }

        return (int) unpack('N', substr($authenticatorData, 33, 4))[1];
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), false);

        return $decoded === false ? '' : $decoded;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}
