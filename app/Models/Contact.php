<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent end-user identity scoped to a single Channel. For WhatsApp the
 * identifier is the wa_id (E.164 without the leading +). For widget it's the
 * ephemeral session_token. A future Phase 3 refactor may de-duplicate across
 * channels, but scoping by channel keeps semantics unambiguous today.
 */
class Contact extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $fillable = [
        'channel_id',
        'identifier',
        'profile_name',
        'email',
        'phone_e164',
        'locale',
        'metadata',
        'last_inbound_at',
        'last_outbound_at',
        'opted_out_at',
        'user_agent',
        'ip_address',
    ];

    /**
     * Store raw IP on the server only; don't leak to clients.
     */
    protected $hidden = [
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'opted_out_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cont';
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    /**
     * WhatsApp "session window" rule: free-form messages only allowed within
     * 24 hours of the contact's last inbound. Templates bypass this rule.
     */
    public function isWithinSessionWindow(?\DateTimeInterface $now = null): bool
    {
        if ($this->last_inbound_at === null) {
            return false;
        }

        $now ??= now();

        return $this->last_inbound_at->diffInHours($now) < 24;
    }
}
