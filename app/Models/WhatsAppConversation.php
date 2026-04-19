<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'channel_id',
        'contact_id',
        'title',
        'metadata',
        'flow_state',
        'message_count',
        'status',
        'assigned_user_id',
        'first_response_at',
        'total_response_time_ms',
        'is_resolved',
        'is_abandoned',
        'abandoned_at',
        'last_inbound_at',
        'last_outbound_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'flow_state' => 'array',
            'status' => ConversationStatus::class,
            'message_count' => 'integer',
            'total_response_time_ms' => 'integer',
            'is_resolved' => 'boolean',
            'is_abandoned' => 'boolean',
            'first_response_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id')->orderBy('created_at');
    }

    public function connection(): ?WhatsAppConnection
    {
        return $this->channel?->whatsAppConnection;
    }

    public function isAutoReplyEnabled(): bool
    {
        return ! $this->status->suppressesAutoReply();
    }
}
