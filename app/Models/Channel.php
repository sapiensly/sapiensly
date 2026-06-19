<?php

namespace App\Models;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared abstraction for every delivery surface (widget, WhatsApp, and future
 * channels). The channel owns tenant scope, agent/team target, status, and
 * metadata; channel-type-specific config lives on satellite tables
 * (`chatbots` for widgets, `whatsapp_connections` for WhatsApp).
 */
class Channel extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;
    use UsesPlatformConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'channel_type',
        'name',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'channel_type' => ChannelType::class,
            'status' => ChannelStatus::class,
            'metadata' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'chan';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatbot(): HasOne
    {
        return $this->hasOne(Chatbot::class);
    }

    public function whatsAppConnection(): HasOne
    {
        return $this->hasOne(WhatsAppConnection::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function whatsAppConversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class);
    }
}
