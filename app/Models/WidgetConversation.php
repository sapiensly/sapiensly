<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WidgetConversation extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'chatbot_id',
        'channel_id',
        'contact_id',
        'widget_session_id',
        'title',
        'metadata',
        'message_count',
        'rating',
        'feedback',
        'first_response_at',
        'total_response_time_ms',
        'is_resolved',
        'is_abandoned',
        'abandoned_at',
        'flow_state',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'flow_state' => 'array',
            'rating' => 'integer',
            'message_count' => 'integer',
            'total_response_time_ms' => 'integer',
            'is_resolved' => 'boolean',
            'is_abandoned' => 'boolean',
            'first_response_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WidgetSession::class, 'widget_session_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WidgetMessage::class)->orderBy('created_at');
    }

    public function lastMessage(): ?WidgetMessage
    {
        return $this->messages()->latest()->first();
    }

    public function addMessage(MessageRole $role, string $content, array $metadata = []): WidgetMessage
    {
        $message = $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);

        $this->increment('message_count');

        return $message;
    }

    public function recordResponse(int $responseTimeMs): void
    {
        $updates = [
            'total_response_time_ms' => $this->total_response_time_ms + $responseTimeMs,
        ];

        if (! $this->first_response_at) {
            $updates['first_response_at'] = now();
        }

        $this->update($updates);
    }

    public function submitFeedback(int $rating, ?string $feedback = null): void
    {
        $this->update([
            'rating' => $rating,
            'feedback' => $feedback,
            'is_resolved' => true,
        ]);
    }

    public function markAbandoned(): void
    {
        $this->update([
            'is_abandoned' => true,
            'abandoned_at' => now(),
        ]);
    }

    public function getAverageResponseTime(): ?int
    {
        $assistantMessages = $this->messages()->where('role', MessageRole::Assistant)->count();

        if ($assistantMessages === 0) {
            return null;
        }

        return (int) ($this->total_response_time_ms / $assistantMessages);
    }
}
