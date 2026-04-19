<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * The satellite model for a WhatsApp channel. Holds Meta Cloud API-specific
 * credentials, phone metadata, and per-connection delivery state. The tenant
 * scope and target (agent/team) live on the parent Channel — this table is
 * strictly channel-type configuration.
 */
class WhatsAppConnection extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'channel_id',
        'display_phone_number',
        'phone_number_id',
        'business_account_id',
        'provider',
        'auth_config',
        'webhook_verify_token',
        'messaging_tier',
        'allow_insecure_tls',
        'last_verified_at',
        'last_webhook_received_at',
    ];

    /**
     * Hide raw credentials from serialization; `maskedAuthConfig()` exposes
     * safe UI-ready values.
     */
    protected $hidden = [
        'auth_config',
    ];

    protected function casts(): array
    {
        return [
            'auth_config' => 'encrypted:array',
            'allow_insecure_tls' => 'boolean',
            'last_verified_at' => 'datetime',
            'last_webhook_received_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'wac';
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_connection_id');
    }

    /**
     * All conversations that flow through this connection (via the shared
     * Channel abstraction — WhatsAppConversation carries `channel_id` not
     * `whatsapp_connection_id`).
     */
    public function conversations(): HasManyThrough
    {
        return $this->hasManyThrough(
            WhatsAppConversation::class,
            Channel::class,
            'id',          // channels.id
            'channel_id',  // whatsapp_conversations.channel_id
            'channel_id',  // whatsapp_connections.channel_id
            'id',          // channels.id
        );
    }

    /**
     * UI-safe projection of credentials: the important identifiers are shown,
     * secrets are short-circuited to masked placeholders.
     *
     * @return array<string, string|null>
     */
    public function maskedAuthConfig(): array
    {
        $cfg = $this->auth_config ?? [];
        $mask = static fn (?string $value): ?string => $value === null || $value === ''
            ? null
            : substr($value, 0, 4).'…'.substr($value, -4);

        return [
            'phone_number_id' => $cfg['phone_number_id'] ?? null,
            'whatsapp_business_account_id' => $cfg['whatsapp_business_account_id'] ?? null,
            'app_id' => $cfg['app_id'] ?? null,
            'graph_api_version' => $cfg['graph_api_version'] ?? 'v20.0',
            'access_token_masked' => $mask($cfg['access_token'] ?? null),
            'app_secret_masked' => $mask($cfg['app_secret'] ?? null),
        ];
    }
}
