<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\ChatAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAttachment extends Model
{
    /** @use HasFactory<ChatAttachmentFactory> */
    use HasFactory, HasPrefixedUlid;

    use UsesTenantConnection;

    protected $fillable = [
        'chat_id',
        'chat_message_id',
        'user_id',
        'organization_id',
        'disk',
        'storage_path',
        'original_name',
        'mime',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'catt';
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function isAudio(): bool
    {
        return str_starts_with((string) $this->mime, 'audio/');
    }
}
