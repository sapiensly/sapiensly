<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'whatsapp_conversation_id',
        'role',
        'direction',
        'content',
        'content_type',
        'media_url',
        'media_local_path',
        'media_mime',
        'template_name',
        'template_language',
        'wamid',
        'provider_message_id',
        'status',
        'status_updates',
        'error_code',
        'error_message',
        'sender_user_id',
        'tokens_used',
        'model',
        'metadata',
        'response_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'direction' => MessageDirection::class,
            'status' => MessageStatus::class,
            'content_type' => WhatsAppContentType::class,
            'status_updates' => 'array',
            'metadata' => 'array',
            'tokens_used' => 'integer',
            'response_time_ms' => 'integer',
            'error_code' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'wmsg';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * Update the status if the incoming value is a strictly later state than
     * the current one. Out-of-order webhook deliveries (sent → delivered → read
     * arriving in any order) are common and must not overwrite a more advanced
     * state. Appends the transition to `status_updates` for audit.
     */
    public function advanceStatusTo(MessageStatus $next, array $context = []): bool
    {
        if ($this->status === MessageStatus::Failed) {
            return false;
        }

        if ($next->rank() <= $this->status->rank() && $next !== MessageStatus::Failed) {
            return false;
        }

        $history = $this->status_updates ?? [];
        $history[] = array_merge($context, [
            'from' => $this->status->value,
            'to' => $next->value,
            'at' => now()->toIso8601String(),
        ]);

        $this->update([
            'status' => $next,
            'status_updates' => $history,
        ]);

        return true;
    }
}
