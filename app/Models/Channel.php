<?php

namespace App\Models;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
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

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'channel_type',
        'name',
        'agent_id',
        'agent_team_id',
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

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentTeam(): BelongsTo
    {
        return $this->belongsTo(AgentTeam::class);
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

    public function getTarget(): Agent|AgentTeam|null
    {
        if ($this->agent_id) {
            return $this->agent;
        }
        if ($this->agent_team_id) {
            return $this->agentTeam;
        }

        return null;
    }
}
