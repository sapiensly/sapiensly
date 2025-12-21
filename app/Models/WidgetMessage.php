<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetMessage extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'widget_conversation_id',
        'role',
        'content',
        'tokens_used',
        'model',
        'metadata',
        'response_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tokens_used' => 'integer',
            'metadata' => 'array',
            'response_time_ms' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WidgetConversation::class, 'widget_conversation_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === MessageRole::User;
    }

    public function isFromAssistant(): bool
    {
        return $this->role === MessageRole::Assistant;
    }
}
