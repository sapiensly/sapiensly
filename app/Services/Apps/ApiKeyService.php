<?php

namespace App\Services\Apps;

use App\Models\App;
use App\Models\AppApiKey;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mints and revokes the credentials for an app's REST data API.
 *
 * The plaintext token exists for exactly one return value and is never stored —
 * only its SHA-256. A key that cannot be re-read is a key that cannot be leaked
 * from our side, and it forces the honest UX: shown once, rotated if lost.
 */
class ApiKeyService
{
    /** Bytes of entropy in the secret half. */
    private const SECRET_BYTES = 24;

    public function __construct(
        private readonly AppManifestService $manifests,
    ) {}

    /**
     * @param  array<string, list<string>>|null  $objectScopes  slug => actions; null ⇒ whatever the role allows
     * @return array{key: AppApiKey, token: string}
     *
     * @throws InvalidArgumentException when the role or a scoped object is not real
     */
    public function mint(
        App $app,
        string $name,
        string $roleSlug,
        ?array $objectScopes = null,
        ?User $creator = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new InvalidArgumentException('This app has no published manifest to issue a key against.');
        }

        $roles = array_column($manifest['permissions']['roles'] ?? [], 'slug');
        if (! in_array($roleSlug, $roles, true)) {
            throw new InvalidArgumentException(
                "'{$roleSlug}' is not a role in this app. Available: ".(implode(', ', $roles) ?: 'none').'.',
            );
        }

        if ($objectScopes !== null) {
            $this->assertScopesAreReal($manifest, $objectScopes);
        }

        // sap_<prefix>_<secret>: the prefix is what a human matches against a
        // list and a log line; the secret is the part that must never be stored.
        $prefix = Str::lower(Str::random(8));
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $token = "sap_{$prefix}_{$secret}";

        $key = AppApiKey::create([
            'app_id' => $app->id,
            'organization_id' => $app->organization_id,
            'created_by_user_id' => $creator?->id,
            'name' => $name,
            'prefix' => $prefix,
            'token_hash' => hash('sha256', $token),
            'role_slug' => $roleSlug,
            'scopes' => $objectScopes === null ? ['objects' => '*'] : ['objects' => $objectScopes],
            'expires_at' => $expiresAt,
        ]);

        return ['key' => $key, 'token' => $token];
    }

    /**
     * Resolve a presented token to a usable key, or null. Constant-time by
     * construction: the lookup is on the HASH, so no comparison of the secret
     * itself happens in PHP.
     */
    public function resolve(string $token): ?AppApiKey
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $key = AppApiKey::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return ($key !== null && $key->isUsable()) ? $key : null;
    }

    public function revoke(AppApiKey $key): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * A scope naming an object or action that does not exist is a key its author
     * believes works. Reject it at mint time rather than at the first 403.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, list<string>>  $objectScopes
     */
    private function assertScopesAreReal(array $manifest, array $objectScopes): void
    {
        $slugs = array_column($manifest['objects'] ?? [], 'slug');

        foreach ($objectScopes as $slug => $actions) {
            if (! in_array($slug, $slugs, true)) {
                throw new InvalidArgumentException(
                    "'{$slug}' is not an object in this app. Available: ".(implode(', ', $slugs) ?: 'none').'.',
                );
            }
            if (! is_array($actions) || $actions === []) {
                throw new InvalidArgumentException("Scope for '{$slug}' must list at least one action.");
            }
            $unknown = array_diff($actions, AppApiKey::ACTIONS);
            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    "Unknown action(s) for '{$slug}': ".implode(', ', $unknown).'. Allowed: '.implode(', ', AppApiKey::ACTIONS).'.',
                );
            }
        }
    }
}
