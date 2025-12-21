<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WidgetSession extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $fillable = [
        'chatbot_id',
        'session_token',
        'visitor_email',
        'visitor_name',
        'visitor_metadata',
        'user_agent',
        'ip_address',
        'referrer_url',
        'page_url',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'visitor_metadata' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'ip_address',
    ];

    public static function getIdPrefix(): string
    {
        return 'session';
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WidgetConversation::class);
    }

    public static function generateSessionToken(): string
    {
        return Str::random(64);
    }

    public function touchActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    public function isIdentified(): bool
    {
        return ! empty($this->visitor_email) || ! empty($this->visitor_name);
    }

    public function identify(array $info): void
    {
        $this->update([
            'visitor_email' => $info['email'] ?? $this->visitor_email,
            'visitor_name' => $info['name'] ?? $this->visitor_name,
            'visitor_metadata' => array_merge(
                $this->visitor_metadata ?? [],
                $info['metadata'] ?? []
            ),
        ]);
    }
}
