import axios from 'axios';

/**
 * Asking the device who is holding it.
 *
 * For the action somebody should have to mean: a refund, a write-off, a price
 * override, a deletion. A confirm dialog asks whether you MEANT it; this asks
 * WHO you are, with the fingerprint or the face the device already knows —
 * which is the only kind of second factor a technician holding a phone in the
 * rain will actually use.
 *
 * Everything that matters is checked on the SERVER against a challenge it
 * minted. Nothing here is a security decision: this module's whole job is to
 * carry bytes between the authenticator and that check.
 */
interface Challenge {
    challenge: string;
    credential_ids: string[];
}

function decode(base64url: string): ArrayBuffer {
    const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const raw = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes.buffer;
}

function encode(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

export function canConfirmIdentity(): boolean {
    return (
        typeof window !== 'undefined' &&
        'PublicKeyCredential' in window &&
        typeof navigator !== 'undefined' &&
        navigator.credentials !== undefined
    );
}

/** What the server needs to check an assertion. Null when nothing was signed. */
export interface IdentityProof {
    id: string;
    authenticator_data: string;
    client_data: string;
    signature: string;
}

/**
 * Enrol this device, so it can be asked later.
 *
 * The public key is taken from `getPublicKey()`, which hands it over already
 * encoded as SubjectPublicKeyInfo — the reason nothing on either side of this
 * parses CBOR or reads an attestation.
 */
async function enrol(appSlug: string, challenge: Challenge): Promise<boolean> {
    const credential = (await navigator.credentials.create({
        publicKey: {
            challenge: decode(challenge.challenge),
            rp: { name: 'Sapiensly', id: window.location.hostname },
            user: {
                // Opaque and per device: the server already knows who is asking
                // from the session, and a real user id here would be a stable
                // identifier sitting in an authenticator for ever.
                id: crypto.getRandomValues(new Uint8Array(16)),
                name: appSlug,
                displayName: appSlug,
            },
            // ES256 only, which is what every platform authenticator does. A
            // longer list would mean a verifier that has to handle algorithms
            // nothing in the field produces.
            pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
            authenticatorSelection: {
                // The fingerprint or face on THIS device, not a roaming key
                // somebody has to carry — and user verification required,
                // because "somebody touched the sensor" is not the claim.
                authenticatorAttachment: 'platform',
                userVerification: 'required',
            },
            timeout: 60_000,
            attestation: 'none',
        },
    })) as PublicKeyCredential | null;

    if (credential === null) return false;

    const response = credential.response as AuthenticatorAttestationResponse;
    const spki = response.getPublicKey?.();
    if (!spki) return false;

    await axios.post(`/r/${appSlug}/identity/credentials`, {
        id: credential.id,
        public_key: btoa(String.fromCharCode(...new Uint8Array(spki))),
        client_data: encode(response.clientDataJSON),
        label: navigator.userAgent.slice(0, 120),
    });

    return true;
}

/**
 * Ask, and hand back what the server can check — or null if it did not happen.
 *
 * Null covers a cancelled prompt, a device with no sensor and a browser without
 * WebAuthn. The caller's move is the same in every case: do not run the action,
 * and say why.
 */
export async function confirmIdentity(
    appSlug: string,
): Promise<IdentityProof | null> {
    if (!canConfirmIdentity()) return null;

    try {
        const { data } = await axios.get<Challenge>(
            `/r/${appSlug}/identity/challenge`,
        );

        // Nothing enrolled on this device yet. Enrol, then ask — one prompt for
        // the person either way, and a first use that works rather than an
        // error telling them to go and find a settings screen.
        if (data.credential_ids.length === 0) {
            if (!(await enrol(appSlug, data))) return null;

            return confirmIdentity(appSlug);
        }

        const assertion = (await navigator.credentials.get({
            publicKey: {
                challenge: decode(data.challenge),
                rpId: window.location.hostname,
                allowCredentials: data.credential_ids.map((id) => ({
                    type: 'public-key' as const,
                    id: decode(id),
                })),
                userVerification: 'required',
                timeout: 60_000,
            },
        })) as PublicKeyCredential | null;

        if (assertion === null) return null;

        const response = assertion.response as AuthenticatorAssertionResponse;

        return {
            id: assertion.id,
            authenticator_data: encode(response.authenticatorData),
            client_data: encode(response.clientDataJSON),
            signature: encode(response.signature),
        };
    } catch {
        // Cancelled, timed out, refused, no sensor. All the same to the caller.
        return null;
    }
}
