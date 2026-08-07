<?php

use App\Models\App;
use App\Models\DeviceCredential;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Support\Identity\WebAuthnAssertion;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The gate in front of the action somebody should have to mean.
 *
 * The point of this feature is that it cannot be satisfied by the thing asking
 * — so what is tested is refusal: a signature over the wrong challenge, a
 * replayed one, and an assertion that says a screen was unlocked rather than a
 * person verified. Each of those must leave the record UNWRITTEN, because a
 * gate that fails open is worse than no gate: it is a gate people trust.
 */
function ident_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/** A P-256 key standing in for the one inside a phone's secure enclave. */
function ident_key(): array
{
    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    $details = openssl_pkey_get_details($key);

    // DER, not the PEM openssl hands back: `getPublicKey()` in a browser
    // returns raw SubjectPublicKeyInfo bytes, and a test that sent PEM would be
    // testing a shape no authenticator produces.
    $der = base64_decode(implode('', array_slice(
        array_filter(explode("\n", (string) $details['key'])),
        1,
        -1,
    )), true);

    return ['key' => $key, 'spki' => $der];
}

/** clientDataJSON as a browser builds it. */
function ident_client_data(string $type, string $challenge, string $origin = 'http://localhost'): string
{
    return (string) json_encode([
        'type' => $type,
        'challenge' => WebAuthnAssertion::encode($challenge),
        'origin' => $origin,
    ]);
}

/** authenticatorData: the rp id hash, the flags, and a counter. */
function ident_auth_data(int $flags = 0x05, int $count = 0): string
{
    return hash('sha256', 'localhost', true).chr($flags).pack('N', $count);
}

function ident_sign(mixed $key, string $authData, string $clientData): string
{
    $signature = '';
    openssl_sign(
        $authData.hash('sha256', $clientData, true),
        $signature,
        $key,
        OPENSSL_ALGO_SHA256,
    );

    return $signature;
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);

    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'caja',
        'name' => 'Caja',
    ]);

    $this->objId = ident_id('obj');
    $rolId = ident_id('rol');

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'caja',
        'name' => 'Caja',
        'version' => 1,
        'objects' => [[
            'id' => $this->objId,
            'slug' => 'devoluciones',
            'name' => 'Devolución',
            'fields' => [
                ['id' => ident_id('fld'), 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => $rolId, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
            'object_policies' => [[
                'object_id' => $this->objId, 'role_id' => $rolId,
                'actions' => ['create', 'read', 'update', 'delete'],
            ]],
        ],
    ], $this->user);

    $this->pair = ident_key();
});

/** Enrol the device, exactly as the browser would. */
function ident_enrol(): string
{
    $challenge = test()->actingAs(test()->user)
        ->getJson('/r/caja/identity/challenge')
        ->json('challenge');

    test()->actingAs(test()->user)
        ->postJson('/r/caja/identity/credentials', [
            'id' => 'cred-abc',
            'public_key' => base64_encode(test()->pair['spki']),
            'client_data' => WebAuthnAssertion::encode(
                ident_client_data('webauthn.create', WebAuthnAssertion::decode($challenge)),
            ),
        ])
        ->assertOk();

    return $challenge;
}

/** Approve a refund, signing whatever challenge the caller wants signed. */
function ident_approve(?string $challenge = null, int $flags = 0x05): TestResponse
{
    $challenge ??= test()->actingAs(test()->user)
        ->getJson('/r/caja/identity/challenge')
        ->json('challenge');

    $clientData = ident_client_data('webauthn.get', WebAuthnAssertion::decode($challenge));
    $authData = ident_auth_data($flags);

    return test()->actingAs(test()->user)->postJson('/r/caja/actions', [
        'actions' => [
            ['type' => 'require_identity'],
            [
                'type' => 'create_record',
                'object_id' => test()->objId,
                'values' => ['folio' => 'DEV-1'],
            ],
        ],
        'identity' => [
            'id' => 'cred-abc',
            'authenticator_data' => WebAuthnAssertion::encode($authData),
            'client_data' => WebAuthnAssertion::encode($clientData),
            'signature' => WebAuthnAssertion::encode(
                ident_sign(test()->pair['key'], $authData, $clientData),
            ),
        ],
    ]);
}

it('enrols a device from the key the browser already encoded', function () {
    // No CBOR anywhere: `getPublicKey()` hands over SubjectPublicKeyInfo, which
    // is what openssl reads. That is why this is a hundred lines, not a library.
    ident_enrol();

    expect(DeviceCredential::where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('runs the rest of the sequence once the device answers', function () {
    ident_enrol();

    ident_approve()->assertOk();

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('writes nothing when the signature is over a challenge we never minted', function () {
    ident_enrol();

    ident_approve(WebAuthnAssertion::encode(random_bytes(32)))
        ->assertForbidden();

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('refuses the same assertion twice', function () {
    // A challenge is single use, so one fingerprint approves one action — not
    // every action taken in the next two minutes by whoever is holding the
    // phone afterwards.
    ident_enrol();

    $challenge = $this->actingAs($this->user)
        ->getJson('/r/caja/identity/challenge')
        ->json('challenge');

    ident_approve($challenge)->assertOk();
    ident_approve($challenge)->assertForbidden();

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('refuses an assertion that only says somebody was present', function () {
    // UP without UV: a screen that happens to be unlocked in a shared van.
    // "Somebody touched it" is not the claim an approval makes.
    ident_enrol();

    ident_approve(flags: 0x01)->assertForbidden();

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('refuses a device that was never enrolled', function () {
    ident_approve()->assertForbidden();

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('refuses an enrolment that answers no challenge of ours', function () {
    $this->actingAs($this->user)->getJson('/r/caja/identity/challenge')->assertOk();

    $this->actingAs($this->user)
        ->postJson('/r/caja/identity/credentials', [
            'id' => 'cred-xyz',
            'public_key' => base64_encode($this->pair['spki']),
            'client_data' => WebAuthnAssertion::encode(
                ident_client_data('webauthn.create', random_bytes(32)),
            ),
        ])
        ->assertStatus(422);

    expect(DeviceCredential::count())->toBe(0);
});
