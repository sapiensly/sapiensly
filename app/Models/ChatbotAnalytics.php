<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotAnalytics extends Model
{
    use HasFactory;

    protected $table = 'chatbot_analytics';

    protected $fillable = [
        'chatbot_id',
        'date',
        'hour',
        'total_conversations',
        'total_messages',
        'unique_visitors',
        'avg_response_time_ms',
        'avg_rating',
        'total_ratings',
        'resolved_count',
        'abandoned_count',
        'resolution_rate',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_conversations' => 'integer',
            'total_messages' => 'integer',
            'unique_visitors' => 'integer',
            'avg_response_time_ms' => 'integer',
            'avg_rating' => 'float',
            'total_ratings' => 'integer',
            'resolved_count' => 'integer',
            'abandoned_count' => 'integer',
            'resolution_rate' => 'float',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public static function aggregateForDate(Chatbot $chatbot, string $date, ?string $hour = null): self
    {
        $query = $chatbot->conversations()
            ->whereDate('created_at', $date);

        if ($hour !== null) {
            $driver = $chatbot->getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $query->whereRaw("strftime('%H', created_at) = ?", [sprintf('%02d', $hour)]);
            } else {
                $query->whereRaw('EXTRACT(HOUR FROM created_at) = ?', [$hour]);
            }
        }

        $conversations = $query->get();

        $totalConversations = $conversations->count();
        $totalMessages = $conversations->sum('message_count');
        $uniqueVisitors = $conversations->pluck('widget_session_id')->unique()->count();

        $withResponses = $conversations->filter(fn ($c) => $c->total_response_time_ms > 0);
        $avgResponseTime = $withResponses->count() > 0
            ? (int) $withResponses->avg('total_response_time_ms')
            : 0;

        $rated = $conversations->whereNotNull('rating');
        $avgRating = $rated->count() > 0 ? $rated->avg('rating') : null;

        $resolvedCount = $conversations->where('is_resolved', true)->count();
        $abandonedCount = $conversations->where('is_abandoned', true)->count();
        $resolutionRate = $totalConversations > 0
            ? ($resolvedCount / $totalConversations) * 100
            : 0;

        return self::updateOrCreate(
            [
                'chatbot_id' => $chatbot->id,
                'date' => $date,
                'hour' => $hour,
            ],
            [
                'total_conversations' => $totalConversations,
                'total_messages' => $totalMessages,
                'unique_visitors' => $uniqueVisitors,
                'avg_response_time_ms' => $avgResponseTime,
                'avg_rating' => $avgRating,
                'total_ratings' => $rated->count(),
                'resolved_count' => $resolvedCount,
                'abandoned_count' => $abandonedCount,
                'resolution_rate' => $resolutionRate,
            ]
        );
    }
}
