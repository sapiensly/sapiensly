<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tokens_used',
        'model',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tokens_used' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
