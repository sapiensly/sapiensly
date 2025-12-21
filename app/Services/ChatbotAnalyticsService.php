<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\ChatbotAnalytics;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for chatbot analytics aggregation and retrieval.
 */
class ChatbotAnalyticsService
{
    /**
     * Get overview statistics for a chatbot.
     */
    public function getOverview(Chatbot $chatbot, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate ??= now()->subDays(30);
        $endDate ??= now();

        $conversations = $chatbot->conversations()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $sessions = $chatbot->sessions()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $totalConversations = $conversations->count();
        $totalMessages = $conversations->sum('message_count');

        // Average response time
        $withResponses = $conversations->filter(fn ($c) => $c->total_response_time_ms > 0);
        $avgResponseTime = $withResponses->count() > 0
            ? (int) $withResponses->avg('total_response_time_ms')
            : 0;

        // Ratings
        $rated = $conversations->whereNotNull('rating');
        $avgRating = $rated->count() > 0 ? round($rated->avg('rating'), 1) : null;
        $totalRatings = $rated->count();

        // Resolution stats
        $resolvedCount = $conversations->where('is_resolved', true)->count();
        $abandonedCount = $conversations->where('is_abandoned', true)->count();
        $resolutionRate = $totalConversations > 0
            ? round(($resolvedCount / $totalConversations) * 100, 1)
            : 0;

        // Calculate trends (compare to previous period)
        $periodLength = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($periodLength);
        $previousEnd = $startDate->copy()->subDay();

        $previousConversations = $chatbot->conversations()
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->count();

        $conversationsTrend = $previousConversations > 0
            ? round((($totalConversations - $previousConversations) / $previousConversations) * 100, 1)
            : ($totalConversations > 0 ? 100 : 0);

        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'total_conversations' => $totalConversations,
            'conversations_trend' => $conversationsTrend,
            'total_messages' => $totalMessages,
            'unique_sessions' => $sessions,
            'avg_response_time_ms' => $avgResponseTime,
            'avg_rating' => $avgRating,
            'total_ratings' => $totalRatings,
            'resolved_count' => $resolvedCount,
            'abandoned_count' => $abandonedCount,
            'resolution_rate' => $resolutionRate,
            'messages_per_conversation' => $totalConversations > 0
                ? round($totalMessages / $totalConversations, 1)
                : 0,
        ];
    }

    /**
     * Get daily conversation counts for charting.
     */
    public function getDailyConversations(
        Chatbot $chatbot,
        Carbon $startDate,
        Carbon $endDate
    ): Collection {
        $period = CarbonPeriod::create($startDate, $endDate);

        // Get aggregated data from analytics table first
        $analytics = ChatbotAnalytics::where('chatbot_id', $chatbot->id)
            ->whereNull('hour')
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        // Fill in missing dates with zeros
        return collect($period)->map(function ($date) use ($analytics) {
            $dateString = $date->toDateString();
            $record = $analytics->get($dateString);

            return [
                'date' => $dateString,
                'conversations' => $record?->total_conversations ?? 0,
                'messages' => $record?->total_messages ?? 0,
                'visitors' => $record?->unique_visitors ?? 0,
            ];
        });
    }

    /**
     * Get hourly distribution for a specific date.
     */
    public function getHourlyDistribution(Chatbot $chatbot, Carbon $date): Collection
    {
        // Get aggregated hourly data
        $analytics = ChatbotAnalytics::where('chatbot_id', $chatbot->id)
            ->whereDate('date', $date)
            ->whereNotNull('hour')
            ->get()
            ->keyBy('hour');

        // Fill in all 24 hours
        return collect(range(0, 23))->map(function ($hour) use ($analytics) {
            $record = $analytics->get($hour);

            return [
                'hour' => $hour,
                'label' => sprintf('%02d:00', $hour),
                'conversations' => $record?->total_conversations ?? 0,
                'messages' => $record?->total_messages ?? 0,
            ];
        });
    }

    /**
     * Get rating distribution.
     */
    public function getRatingDistribution(
        Chatbot $chatbot,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        $startDate ??= now()->subDays(30);
        $endDate ??= now();

        $ratings = $chatbot->conversations()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('rating')
            ->select('rating', DB::raw('COUNT(*) as count'))
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        // Ensure all ratings 1-5 are present
        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distribution[$i] = $ratings[$i] ?? 0;
        }

        return $distribution;
    }

    /**
     * Get response time distribution.
     */
    public function getResponseTimeDistribution(
        Chatbot $chatbot,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        $startDate ??= now()->subDays(30);
        $endDate ??= now();

        $conversations = $chatbot->conversations()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('total_response_time_ms', '>', 0)
            ->select('total_response_time_ms', 'message_count')
            ->get();

        if ($conversations->isEmpty()) {
            return [
                'fast' => 0,      // < 1s
                'normal' => 0,   // 1-3s
                'slow' => 0,     // 3-5s
                'very_slow' => 0, // > 5s
            ];
        }

        // Calculate average per message
        $times = $conversations->map(function ($c) {
            return $c->message_count > 0
                ? $c->total_response_time_ms / $c->message_count
                : 0;
        })->filter(fn ($t) => $t > 0);

        return [
            'fast' => $times->filter(fn ($t) => $t < 1000)->count(),
            'normal' => $times->filter(fn ($t) => $t >= 1000 && $t < 3000)->count(),
            'slow' => $times->filter(fn ($t) => $t >= 3000 && $t < 5000)->count(),
            'very_slow' => $times->filter(fn ($t) => $t >= 5000)->count(),
        ];
    }

    /**
     * Get top conversation topics based on first messages.
     */
    public function getTopTopics(
        Chatbot $chatbot,
        int $limit = 10,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): Collection {
        $startDate ??= now()->subDays(30);
        $endDate ??= now();

        // Get first messages from conversations
        $conversationIds = $chatbot->conversations()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return collect();
        }

        // Get first user messages
        $firstMessages = WidgetMessage::whereIn('widget_conversation_id', $conversationIds)
            ->where('role', 'user')
            ->select('widget_conversation_id', 'content')
            ->get()
            ->unique('widget_conversation_id')
            ->pluck('content');

        // Simple keyword extraction (first 5 words)
        return $firstMessages
            ->map(fn ($content) => strtolower(implode(' ', array_slice(explode(' ', $content), 0, 5))))
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->map(fn ($count, $topic) => [
                'topic' => $topic,
                'count' => $count,
            ])
            ->values();
    }

    /**
     * Aggregate analytics for a specific date.
     */
    public function aggregateForDate(Chatbot $chatbot, Carbon $date): void
    {
        // Aggregate daily total
        ChatbotAnalytics::aggregateForDate($chatbot, $date->toDateString());

        // Aggregate hourly breakdowns
        for ($hour = 0; $hour < 24; $hour++) {
            ChatbotAnalytics::aggregateForDate($chatbot, $date->toDateString(), (string) $hour);
        }
    }

    /**
     * Aggregate analytics for all chatbots for a date.
     */
    public function aggregateAllForDate(Carbon $date): int
    {
        $chatbots = Chatbot::all();
        $count = 0;

        foreach ($chatbots as $chatbot) {
            $this->aggregateForDate($chatbot, $date);
            $count++;
        }

        return $count;
    }

    /**
     * Mark abandoned conversations.
     *
     * A conversation is considered abandoned if:
     * - Last activity was more than 30 minutes ago
     * - The conversation is not resolved
     * - The last message was from the user (waiting for response)
     */
    public function markAbandonedConversations(int $minutesThreshold = 30): int
    {
        $threshold = now()->subMinutes($minutesThreshold);

        // Get conversations that might be abandoned
        $candidates = WidgetConversation::where('is_resolved', false)
            ->where('is_abandoned', false)
            ->where('updated_at', '<', $threshold)
            ->get();

        $count = 0;
        foreach ($candidates as $conversation) {
            // Check if last message was from user
            $lastMessage = $conversation->messages()->latest()->first();
            if ($lastMessage && $lastMessage->role->value === 'user') {
                $conversation->update([
                    'is_abandoned' => true,
                    'abandoned_at' => now(),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get comparison data for two periods.
     */
    public function getComparison(
        Chatbot $chatbot,
        Carbon $currentStart,
        Carbon $currentEnd,
        Carbon $previousStart,
        Carbon $previousEnd
    ): array {
        $current = $this->getOverview($chatbot, $currentStart, $currentEnd);
        $previous = $this->getOverview($chatbot, $previousStart, $previousEnd);

        $calculateChange = function ($current, $previous) {
            if ($previous == 0) {
                return $current > 0 ? 100 : 0;
            }

            return round((($current - $previous) / $previous) * 100, 1);
        };

        return [
            'current' => $current,
            'previous' => $previous,
            'changes' => [
                'conversations' => $calculateChange(
                    $current['total_conversations'],
                    $previous['total_conversations']
                ),
                'messages' => $calculateChange(
                    $current['total_messages'],
                    $previous['total_messages']
                ),
                'resolution_rate' => $calculateChange(
                    $current['resolution_rate'],
                    $previous['resolution_rate']
                ),
                'avg_rating' => $calculateChange(
                    $current['avg_rating'] ?? 0,
                    $previous['avg_rating'] ?? 0
                ),
            ],
        ];
    }
}
