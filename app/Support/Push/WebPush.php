<?php

namespace App\Support\Push;

use RuntimeException;

/**
 * The two pieces of cryptography a browser demands before it will show a
 * notification: the payload encrypted to the subscription's own keys (RFC 8291,
 * `aes128gcm`), and a signed claim that the sender is who it says it is
 * (RFC 8292, VAPID).
 *
 * Written against the RFCs with openssl rather than pulled in as a package,
 * because it is about a hundred lines of well-specified steps and both specs
 * publish worked examples — {@see tests/Unit/Support/Push/WebPushTest.php}
 * reproduces RFC 8291's byte for byte, which is a stronger check than trusting
 * a dependency and a weaker one than it looks like without that vector.
 *
 * Nothing here reaches the network. It turns a message plus a subscription into
 * the bytes and the headers a POST needs, which keeps the part that must be
 * exactly right testable without a push service.
 */
final class WebPush
{
    /** The record size we declare. One record, always — these payloads are tiny. */
    private const RECORD_SIZE = 4096;

    /** An uncompressed P-256 point: 0x04 then x and y, 32 bytes each. */
    private const POINT_LENGTH = 65;

    /**
     * Encrypt a message for one subscription.
     *
     * `salt` and `serverKeys` exist for the test vector and for nothing else —
     * left null, both are freshly random per message, which is what makes the
     * nonce reuse this scheme would otherwise be vulnerable to impossible.
     *
     * @param  string  $p256dh  the subscription's public key, base64url
     * @param  string  $auth  the subscription's auth secret, base64url
     * @param  array{private: string, public: string}|null  $serverKeys  raw bytes
     */
    public static function encrypt(
        string $payload,
        string $p256dh,
        string $auth,
        ?string $salt = null,
        ?array $serverKeys = null,
    ): string {
        $userPublic = self::decode($p256dh);
        $authSecret = self::decode($auth);

        if (strlen($userPublic) !== self::POINT_LENGTH) {
            throw new RuntimeException('The subscription key is not a P-256 point.');
        }

        $keys = $serverKeys ?? self::generateKeyPair();
        $salt ??= random_bytes(16);

        $shared = self::sharedSecret($keys['private'], $userPublic);

        // RFC 8291 §3.3. The context binds the secret to BOTH public keys, so a
        // payload encrypted for one subscription cannot be replayed at another.
        $keyInfo = "WebPush: info\0".$userPublic.$keys['public'];
        $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);

        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt(
            // 0x02 is the last-record delimiter; there is only ever one record.
            $payload."\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt the push payload.');
        }

        // The aes128gcm header (RFC 8188 §2), then the one record.
        return $salt
            .pack('N', self::RECORD_SIZE)
            .chr(self::POINT_LENGTH)
            .$keys['public']
            .$ciphertext
            .$tag;
    }

    /**
     * The `Authorization` header value proving who is sending this.
     *
     * `audience` is the push service's ORIGIN and nothing more — a token minted
     * for one service must not be presentable at another, which is the entire
     * point of the claim.
     *
     * @param  string  $privateKey  the application server's private key, base64url raw
     * @param  string  $publicKey  its public key, base64url raw
     */
    public static function authorization(
        string $audience,
        string $subject,
        string $privateKey,
        string $publicKey,
        ?int $expiresAt = null,
    ): string {
        $header = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::encode((string) json_encode([
            'aud' => $audience,
            // Twelve hours: the spec's ceiling is 24 and a token that lives as
            // long as it is allowed to is a token worth stealing.
            'exp' => $expiresAt ?? (time() + 12 * 3600),
            'sub' => $subject,
        ]));

        $signature = self::sign($header.'.'.$claims, self::decode($privateKey));

        return 'vapid t='.$header.'.'.$claims.'.'.self::encode($signature)
            .', k='.rtrim(strtr($publicKey, '+/', '-_'), '=');
    }

    /**
     * A fresh P-256 pair, raw.
     *
     * @return array{private: string, public: string}
     */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('This PHP build cannot generate P-256 keys.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['ec'])) {
            throw new RuntimeException('Could not read the generated key.');
        }

        return [
            'private' => str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT),
            'public' => "\x04"
                .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    /** Base64url, unpadded — every field in both specs is carried this way. */
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), false);

        return $decoded === false ? '' : $decoded;
    }

    /** ECDH between our private key and the subscription's public point. */
    private static function sharedSecret(string $privateRaw, string $publicPoint): string
    {
        $private = openssl_pkey_get_private(self::privateKeyPem($privateRaw));
        $public = openssl_pkey_get_public(self::publicKeyPem($publicPoint));

        if ($private === false || $public === false) {
            throw new RuntimeException('Could not read the push keys.');
        }

        $secret = openssl_pkey_derive($public, $private, 32);
        if ($secret === false) {
            throw new RuntimeException('Could not derive the shared secret.');
        }

        return str_pad($secret, 32, "\0", STR_PAD_LEFT);
    }

    /**
     * ES256: a raw r‖s pair, not the DER openssl hands back.
     *
     * The conversion is the step everyone gets wrong, because a DER INTEGER is
     * minimally encoded — it drops a leading zero byte and gains one when the
     * high bit is set — so a signature is the right length about half the time
     * and a naive implementation passes its first test and fails in production.
     */
    private static function sign(string $input, string $privateRaw): string
    {
        $key = openssl_pkey_get_private(self::privateKeyPem($privateRaw));
        if ($key === false) {
            throw new RuntimeException('Could not read the VAPID private key.');
        }

        $der = '';
        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }

        // SEQUENCE { INTEGER r, INTEGER s }
        $offset = 2 + (ord($der[1]) > 0x80 ? ord($der[1]) - 0x80 : 0);
        $offset += 1;
        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $offset += 2 + $rLength;
        $sLength = ord($der[$offset]);
        $s = substr($der, $offset + 1, $sLength);

        return self::pad($r).self::pad($s);
    }

    /** 32 bytes, left-padded, with any DER sign byte removed. */
    private static function pad(string $value): string
    {
        $value = ltrim($value, "\0");

        return str_pad($value, 32, "\0", STR_PAD_LEFT);
    }

    /**
     * A raw 32-byte scalar as a PEM openssl will read.
     *
     * A SEC1 ECPrivateKey with the optional public point LEFT OUT: openssl
     * multiplies the scalar by the generator when it parses the key, so the
     * point does not have to be computed here — which is the only reason this
     * whole file needs no big-integer arithmetic.
     */
    private static function privateKeyPem(string $raw): string
    {
        $der = hex2bin('30310201010420')
            .str_pad($raw, 32, "\0", STR_PAD_LEFT)
            .hex2bin('a00a06082a8648ce3d030107');

        return "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";
    }

    /** A raw point wrapped as a SubjectPublicKeyInfo. */
    private static function publicKeyPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}
