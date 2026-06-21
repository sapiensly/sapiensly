<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory, HasPrefixedUlid;

    use UsesTenantConnection;

    protected $fillable = [
        'chat_id',
        'role',
        'content',
        'model',
        'status',
        'error',
        'agent_id',
        'message_type',
        'agent_data_context',
        'action_payload',
        'consultation_context',
    ];

    protected function casts(): array
    {
        return [
            'agent_data_context' => 'array',
            'action_payload' => 'array',
            'consultation_context' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cmsg';
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'chat_message_id');
    }
}
