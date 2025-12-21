<?php

namespace App\Http\Controllers;

use App\Models\Chatbot;
use App\Services\ChatbotAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for chatbot analytics dashboard.
 */
class ChatbotAnalyticsController extends Controller
{
    public function __construct(
        private ChatbotAnalyticsService $analyticsService
    ) {}

    /**
     * Display the analytics dashboard for a chatbot.
     *
     * GET /chatbots/{chatbot}/analytics
     */
    public function show(Request $request, Chatbot $chatbot): Response
    {
        $this->authorize('view', $chatbot);

        // Get date range from query params or default to last 30 days
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))
            : now()->subDays(30);

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))
            : now();

        // Get overview statistics
        $overview = $this->analyticsService->getOverview($chatbot, $startDate, $endDate);

        // Get daily data for charts
        $dailyData = $this->analyticsService->getDailyConversations($chatbot, $startDate, $endDate);

        // Get rating distribution
        $ratingDistribution = $this->analyticsService->getRatingDistribution($chatbot, $startDate, $endDate);

        // Get response time distribution
        $responseTimeDistribution = $this->analyticsService->getResponseTimeDistribution($chatbot, $startDate, $endDate);

        // Get top topics
        $topTopics = $this->analyticsService->getTopTopics($chatbot, 10, $startDate, $endDate);

        return Inertia::render('chatbots/Analytics', [
            'chatbot' => [
                'id' => $chatbot->id,
                'name' => $chatbot->name,
                'status' => $chatbot->status->value,
            ],
            'dateRange' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'overview' => $overview,
            'dailyData' => $dailyData->values()->all(),
            'ratingDistribution' => $ratingDistribution,
            'responseTimeDistribution' => $responseTimeDistribution,
            'topTopics' => $topTopics->all(),
        ]);
    }

    /**
     * Get analytics data as JSON for API requests.
     *
     * GET /chatbots/{chatbot}/analytics/data
     */
    public function data(Request $request, Chatbot $chatbot)
    {
        $this->authorize('view', $chatbot);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'type' => ['nullable', 'string', 'in:overview,daily,hourly,ratings,response_times,topics,comparison'],
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->subDays(30);

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now();

        $type = $validated['type'] ?? 'overview';

        return match ($type) {
            'overview' => response()->json($this->analyticsService->getOverview($chatbot, $startDate, $endDate)),
            'daily' => response()->json($this->analyticsService->getDailyConversations($chatbot, $startDate, $endDate)),
            'hourly' => response()->json($this->analyticsService->getHourlyDistribution($chatbot, $startDate)),
            'ratings' => response()->json($this->analyticsService->getRatingDistribution($chatbot, $startDate, $endDate)),
            'response_times' => response()->json($this->analyticsService->getResponseTimeDistribution($chatbot, $startDate, $endDate)),
            'topics' => response()->json($this->analyticsService->getTopTopics($chatbot, 10, $startDate, $endDate)),
            'comparison' => $this->getComparisonData($chatbot, $startDate, $endDate),
            default => response()->json(['error' => 'Invalid type'], 400),
        };
    }

    /**
     * Get comparison data for two periods.
     */
    private function getComparisonData(Chatbot $chatbot, Carbon $currentStart, Carbon $currentEnd)
    {
        $periodLength = $currentStart->diffInDays($currentEnd);
        $previousStart = $currentStart->copy()->subDays($periodLength);
        $previousEnd = $currentStart->copy()->subDay();

        return response()->json(
            $this->analyticsService->getComparison(
                $chatbot,
                $currentStart,
                $currentEnd,
                $previousStart,
                $previousEnd
            )
        );
    }
}
