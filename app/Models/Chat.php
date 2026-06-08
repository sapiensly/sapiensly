<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\ChatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    /** @use HasFactory<ChatFactory> */
    use HasFactory, HasPrefixedUlid, HasVisibility;

    use UsesTenantConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'chat_project_id',
        'title',
        'model',
        'summary',
        'summary_through_message_id',
        'title_refined_at',
        'agent_id',
        'tool_ids',
        'visibility',
        'last_message_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'tool_ids' => 'array',
            'last_message_at' => 'datetime',
            'title_refined_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'chat';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ChatProject::class, 'chat_project_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_id')->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'chat_id');
    }
}
