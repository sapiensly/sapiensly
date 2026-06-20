<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\WidgetAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a visitor uploaded to a widget chatbot conversation. Mirrors
 * {@see ChatAttachment} but is keyed to the widget conversation/message and
 * carries `extracted_text` (for documents) so the bot flow + agents can read
 * the content without re-parsing the file.
 */
class WidgetAttachment extends Model
{
    /** @use HasFactory<WidgetAttachmentFactory> */
    use HasFactory, HasPrefixedUlid;

    use UsesTenantConnection;

    protected $fillable = [
        'widget_conversation_id',
        'widget_message_id',
        'user_id',
        'organization_id',
        'disk',
        'storage_path',
        'original_name',
        'mime',
        'size_bytes',
        'kind',
        'extracted_text',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'watt';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WidgetConversation::class, 'widget_conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(WidgetMessage::class, 'widget_message_id');
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
