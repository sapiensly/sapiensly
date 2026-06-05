<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\ChatProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatProject extends Model
{
    /** @use HasFactory<ChatProjectFactory> */
    use HasFactory, HasPrefixedUlid, HasVisibility;

    use UsesTenantConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'description',
        'custom_instructions',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'cproj';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'chat_project_id');
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'chat_project_knowledge_bases')
            ->withTimestamps();
    }
}
