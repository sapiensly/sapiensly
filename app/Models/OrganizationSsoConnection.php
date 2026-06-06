<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organization enterprise OIDC connection. The owner configures the IdP
 * (issuer + client credentials + endpoints); members then authenticate through
 * the org's dedicated SSO URL. Secrets live encrypted in `config`.
 */
class OrganizationSsoConnection extends Model
{
    use HasPrefixedUlid;
    use UsesPlatformConnection;

    protected $fillable = [
        'organization_id',
        'enabled',
        'auto_provision',
        'issuer',
        'client_id',
        'config',
        'allowed_domains',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'auto_provision' => 'boolean',
            'config' => 'encrypted:array',
            'allowed_domains' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'sso';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether the IdP may sign in / provision a user with the given email,
     * based on the configured allow-list. An empty list permits any domain.
     */
    public function permitsEmail(string $email): bool
    {
        $domains = array_filter(array_map('strtolower', $this->allowed_domains ?? []));
        if ($domains === []) {
            return true;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, $domains, true);
    }
}
