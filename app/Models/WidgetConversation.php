<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WidgetConversation extends Model
{
    use HasFactory, HasUlids;
    use UsesTenantConnection;

    protected $fillable = [
        'chatbot_id',
        'channel_id',
        'contact_id',
        'widget_session_id',
        'title',
        'status',
        'assigned_user_id',
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
            'status' => ConversationStatus::class,
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

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Whether the bot may answer the next message on this conversation.
     *
     * The same question `GenerateWhatsAppReplyJob` asks, now askable here — a
     * human who takes a conversation over must not be talked over by the bot
     * they took it from.
     */
    public function botMayReply(): bool
    {
        return ! $this->status->suppressesAutoReply();
    }

    /**
     * The transcript, oldest first — the order a conversation is read in.
     *
     * CAREFUL: that order is baked in, and Eloquent's `latest()` only APPENDS an
     * order clause, so `->latest()->first()` on this relation returns the OLDEST
     * row, not the newest. Two separate bugs have been caused by exactly that
     * (the stream guard answering only the first turn, and attachments resolving
     * to the first message forever). Call `reorder()` first when you want the
     * newest.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WidgetMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WidgetAttachment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The newest message on this conversation, of any role.
     *
     * This method used to be `messages()->latest()->first()`, which returns the
     * OLDEST row — see the warning on the relation above. It had no callers,
     * which is the only reason it never caused a fourth bug: three call sites
     * had each independently discovered the trap and written their own
     * `reorder()` inline. They now come here instead, so the trap is sprung once
     * and stays sprung.
     */
    public function lastMessage(): ?WidgetMessage
    {
        return $this->newestFirst()->first();
    }

    /**
     * The newest message the VISITOR sent — the turn a bot is asked to answer.
     */
    public function lastUserMessage(): ?WidgetMessage
    {
        return $this->newestFirst()->where('role', MessageRole::User)->first();
    }

    /**
     * The transcript in reverse. `reorder()` is load-bearing: it drops the
     * ascending order the relation bakes in, which a chained `latest()` would
     * otherwise lose to.
     */
    private function newestFirst(): HasMany
    {
        return $this->messages()->reorder()->latest('created_at')->latest('id');
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
        // `status` and the two booleans are two spellings of one fact; writing
        // only one of them is how they drift apart.
        $this->update([
            'rating' => $rating,
            'feedback' => $feedback,
            'is_resolved' => true,
            'status' => ConversationStatus::Resolved,
        ]);
    }

    public function markAbandoned(): void
    {
        $this->update([
            'is_abandoned' => true,
            'abandoned_at' => now(),
            'status' => ConversationStatus::Abandoned,
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
