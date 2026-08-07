<?php

use App\Support\Push\WebPush;

/**
 * The encryption a browser demands before it will show a notification.
 *
 * Written against RFC 8291 with openssl rather than pulled in as a package, so
 * the burden here is higher than usual: nothing about a wrong derivation looks
 * wrong. It produces bytes, a push service accepts them, and the browser
 * silently drops the message.
 *
 * So the proof runs in the direction that cannot be faked. The RFC publishes a
 * subscription's key pair, and this file starts by checking those two strings
 * agree with each other under the curve — which no amount of wrong code here
 * could make true. It then encrypts to that subscription and opens the result
 * with the RFC's own private key, using a receiver written out separately
 * below. A message that decrypts to the plaintext with a key this file never
 * chose is a message the browser holding that key can read.
 */
const RFC8291 = [
    'plaintext' => 'When I grow up, I want to be a watermelon',
    'ua_public' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'ua_private' => 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94',
    'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
    'as_public' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
    'as_private' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
    'salt' => 'DGv6ra1nlYgDCS1FRnbzlw',
];

/**
 * The receiver, written from the spec rather than shared with the sender.
 *
 * Deliberately duplicated: importing the code under test to check the code
 * under test would only prove it agrees with itself.
 */
function openPushMessage(string $body, string $uaPrivate, string $auth): string
{
    $salt = substr($body, 0, 16);
    $keyLength = ord($body[20]);
    $senderPublic = substr($body, 21, $keyLength);
    $record = substr($body, 21 + $keyLength);

    $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode(
        hex2bin('30310201010420').str_pad($uaPrivate, 32, "\0", STR_PAD_LEFT).hex2bin('a00a06082a8648ce3d030107')
    ), 64, "\n")."-----END EC PRIVATE KEY-----\n";

    $publicPem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(
        hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$senderPublic
    ), 64, "\n")."-----END PUBLIC KEY-----\n";

    $shared = openssl_pkey_derive(
        openssl_pkey_get_public($publicPem),
        openssl_pkey_get_private($pem),
        32,
    );

    // The receiver's own public key, recomputed rather than passed in.
    $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
    $receiverPublic = "\x04"
        .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
        .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$receiverPublic.$senderPublic, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $plain = openssl_decrypt(
        substr($record, 0, -16),
        'aes-128-gcm',
        $cek,
        OPENSSL_RAW_DATA,
        $nonce,
        substr($record, -16),
    );

    // Trailing 0x02 is the delimiter saying this was the last record.
    return $plain === false ? '' : rtrim($plain, "\x02");
}

it('agrees with the RFC about which public key belongs to which private key', function () {
    // Two strings from the spec, checked against each other by the curve. If
    // the base64 handling or the key encoding in this file were wrong, this is
    // where it shows, before any of the derivation is involved.
    $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode(
        hex2bin('30310201010420')
        .str_pad(WebPush::decode(RFC8291['ua_private']), 32, "\0", STR_PAD_LEFT)
        .hex2bin('a00a06082a8648ce3d030107')
    ), 64, "\n")."-----END EC PRIVATE KEY-----\n";

    $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
    $point = "\x04"
        .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
        .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    expect(WebPush::encode($point))->toBe(RFC8291['ua_public']);
});

it('produces a message the subscription’s own private key can open', function () {
    $body = WebPush::encrypt(
        RFC8291['plaintext'],
        RFC8291['ua_public'],
        RFC8291['auth'],
        WebPush::decode(RFC8291['salt']),
        [
            'private' => WebPush::decode(RFC8291['as_private']),
            'public' => WebPush::decode(RFC8291['as_public']),
        ],
    );

    expect(openPushMessage(
        $body,
        WebPush::decode(RFC8291['ua_private']),
        WebPush::decode(RFC8291['auth']),
    ))->toBe(RFC8291['plaintext']);
});

it('opens a freshly keyed message too, not just the one with the fixed salt', function () {
    // The vector fixes the salt and the ephemeral key; a real send randomises
    // both, and that is the path every actual notification takes.
    $body = WebPush::encrypt('La orden OS-4471 es tuya.', RFC8291['ua_public'], RFC8291['auth']);

    expect(openPushMessage(
        $body,
        WebPush::decode(RFC8291['ua_private']),
        WebPush::decode(RFC8291['auth']),
    ))->toBe('La orden OS-4471 es tuya.');
});

it('lays the header out the way RFC 8188 says', function () {
    $body = WebPush::encrypt('hola', RFC8291['ua_public'], RFC8291['auth']);

    // salt(16) · record size(4) · key length(1) · the sender's public key(65),
    // and only then the record. A push service reads these bytes before the
    // browser ever sees them, so wrong offsets fail at the edge with a message
    // nobody can act on.
    expect(unpack('N', substr($body, 16, 4))[1])->toBe(4096)
        ->and(ord($body[20]))->toBe(65)
        ->and(ord($body[21]))->toBe(4);
});

it('never sends the same salt or the same key twice', function () {
    // The whole scheme rests on it: a repeated salt with a repeated key is a
    // repeated nonce, and a repeated nonce under AES-GCM leaks the plaintext.
    $one = WebPush::encrypt('hola', RFC8291['ua_public'], RFC8291['auth']);
    $two = WebPush::encrypt('hola', RFC8291['ua_public'], RFC8291['auth']);

    expect(substr($one, 0, 16))->not->toBe(substr($two, 0, 16))
        ->and(substr($one, 21, 65))->not->toBe(substr($two, 21, 65));
});

it('refuses a subscription key that is not a point', function () {
    expect(fn () => WebPush::encrypt('hola', WebPush::encode('too short'), RFC8291['auth']))
        ->toThrow(RuntimeException::class);
});

it('signs a VAPID token a push service can verify', function () {
    $keys = WebPush::generateKeyPair();

    $header = WebPush::authorization(
        'https://fcm.googleapis.com',
        'mailto:soporte@example.test',
        WebPush::encode($keys['private']),
        WebPush::encode($keys['public']),
        expiresAt: 2_000_000_000,
    );

    expect($header)->toStartWith('vapid t=');

    [$token, $key] = explode(', k=', substr($header, strlen('vapid t=')));
    [$head, $claims, $signature] = explode('.', $token);

    expect(json_decode(WebPush::decode($head), true))
        ->toBe(['typ' => 'JWT', 'alg' => 'ES256'])
        ->and(json_decode(WebPush::decode($claims), true))
        ->toBe([
            'aud' => 'https://fcm.googleapis.com',
            'exp' => 2_000_000_000,
            'sub' => 'mailto:soporte@example.test',
        ])
        ->and($key)->toBe(WebPush::encode($keys['public']));

    // Verified with openssl rather than eyeballed for length: a signature can
    // be 64 bytes and still be the wrong 64 bytes.
    $der = derFrom(WebPush::decode($signature));
    $pem = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(
        hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$keys['public']
    ), 64, "\n")."-----END PUBLIC KEY-----\n");

    expect(openssl_verify($head.'.'.$claims, $der, $pem, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('signs the same way often enough to catch a short r or s', function () {
    // A DER INTEGER is minimally encoded: it drops a leading zero and gains one
    // when the high bit is set. Over enough signatures the conversion meets
    // every case, and a naive one produces 63 or 65 bytes when it does.
    $keys = WebPush::generateKeyPair();

    for ($i = 0; $i < 40; $i++) {
        $header = WebPush::authorization(
            'https://push.example.test/'.$i,
            'mailto:a@b.test',
            WebPush::encode($keys['private']),
            WebPush::encode($keys['public']),
        );

        [$token] = explode(', k=', substr($header, strlen('vapid t=')));

        expect(strlen(WebPush::decode(explode('.', $token)[2])))->toBe(64);
    }
});

/** Raw r‖s back into the DER openssl_verify wants. The inverse of the sender's step. */
function derFrom(string $raw): string
{
    $encode = function (string $part): string {
        $part = ltrim($part, "\0");
        if ($part === '' || ord($part[0]) > 0x7F) {
            $part = "\0".$part;
        }

        return "\x02".chr(strlen($part)).$part;
    };

    $body = $encode(substr($raw, 0, 32)).$encode(substr($raw, 32));

    return "\x30".chr(strlen($body)).$body;
}
