<?php

use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\ChatbotAnalytics;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use App\Services\ChatbotAnalyticsService;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->triage()->create(['user_id' => $this->user->id]);
    $this->chatbot = Chatbot::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $this->agent->id,
        'status' => ChatbotStatus::Active,
    ]);
    $this->analyticsService = app(ChatbotAnalyticsService::class);
});

describe('analytics page', function () {
    it('displays the analytics page', function () {
        $this->actingAs($this->user)
            ->get(route('chatbots.analytics', $this->chatbot))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('chatbots/Analytics')
                ->has('chatbot')
                ->has('overview')
                ->has('dailyData')
                ->has('ratingDistribution')
                ->has('responseTimeDistribution')
                ->has('topTopics')
            );
    });

    it('returns 403 for chatbots belonging to other users', function () {
        $otherChatbot = Chatbot::factory()->create();

        $this->actingAs($this->user)
            ->get(route('chatbots.analytics', $otherChatbot))
            ->assertForbidden();
    });

    it('accepts date range parameters', function () {
        $this->actingAs($this->user)
            ->get(route('chatbots.analytics', [
                'chatbot' => $this->chatbot->id,
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dateRange.start', '2024-01-01')
                ->where('dateRange.end', '2024-01-31')
            );
    });
});

describe('analytics data endpoint', function () {
    it('returns overview data', function () {
        $this->actingAs($this->user)
            ->getJson(route('chatbots.analytics.data', [
                'chatbot' => $this->chatbot->id,
                'type' => 'overview',
            ]))
            ->assertOk()
            ->assertJsonStructure([
                'period' => ['start', 'end'],
                'total_conversations',
                'conversations_trend',
                'total_messages',
                'unique_sessions',
                'avg_response_time_ms',
                'avg_rating',
                'total_ratings',
                'resolved_count',
                'abandoned_count',
                'resolution_rate',
                'messages_per_conversation',
            ]);
    });

    it('returns daily data', function () {
        $this->actingAs($this->user)
            ->getJson(route('chatbots.analytics.data', [
                'chatbot' => $this->chatbot->id,
                'type' => 'daily',
            ]))
            ->assertOk()
            ->assertJsonIsArray();
    });

    it('returns ratings distribution', function () {
        $this->actingAs($this->user)
            ->getJson(route('chatbots.analytics.data', [
                'chatbot' => $this->chatbot->id,
                'type' => 'ratings',
            ]))
            ->assertOk()
            ->assertJsonStructure([1, 2, 3, 4, 5]);
    });

    it('returns response times distribution', function () {
        $this->actingAs($this->user)
            ->getJson(route('chatbots.analytics.data', [
                'chatbot' => $this->chatbot->id,
                'type' => 'response_times',
            ]))
            ->assertOk()
            ->assertJsonStructure(['fast', 'normal', 'slow', 'very_slow']);
    });
});

describe('ChatbotAnalyticsService', function () {
    it('calculates overview statistics correctly', function () {
        // Create some sessions and conversations
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-123',
        ]);

        $conversation = WidgetConversation::create([
            'chatbot_id' => $this->chatbot->id,
            'widget_session_id' => $session->id,
            'message_count' => 4,
            'rating' => 5,
            'is_resolved' => true,
            'total_response_time_ms' => 2000,
        ]);

        WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'Hello',
        ]);

        WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'Hi there!',
            'response_time_ms' => 1000,
        ]);

        $overview = $this->analyticsService->getOverview($this->chatbot);

        expect($overview['total_conversations'])->toBe(1)
            ->and($overview['total_messages'])->toBe(4)
            ->and($overview['unique_sessions'])->toBe(1)
            ->and($overview['avg_rating'])->toBe(5.0)
            ->and($overview['resolved_count'])->toBe(1)
            ->and($overview['resolution_rate'])->toBe(100.0);
    });

    it('calculates rating distribution', function () {
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-456',
        ]);

        // Create conversations with different ratings
        foreach ([5, 5, 4, 4, 4, 3, 1] as $rating) {
            WidgetConversation::create([
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $session->id,
                'message_count' => 2,
                'rating' => $rating,
            ]);
        }

        $distribution = $this->analyticsService->getRatingDistribution($this->chatbot);

        expect($distribution[5])->toBe(2)
            ->and($distribution[4])->toBe(3)
            ->and($distribution[3])->toBe(1)
            ->and($distribution[2])->toBe(0)
            ->and($distribution[1])->toBe(1);
    });

    it('calculates response time distribution', function () {
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-789',
        ]);

        // Create conversations with different response times
        $responseTimes = [
            ['total' => 500, 'count' => 1],    // fast (500ms per message)
            ['total' => 2000, 'count' => 1],   // normal (2s per message)
            ['total' => 4000, 'count' => 1],   // slow (4s per message)
            ['total' => 6000, 'count' => 1],   // very slow (6s per message)
        ];

        foreach ($responseTimes as $data) {
            WidgetConversation::create([
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $session->id,
                'message_count' => $data['count'],
                'total_response_time_ms' => $data['total'],
            ]);
        }

        $distribution = $this->analyticsService->getResponseTimeDistribution($this->chatbot);

        expect($distribution['fast'])->toBe(1)
            ->and($distribution['normal'])->toBe(1)
            ->and($distribution['slow'])->toBe(1)
            ->and($distribution['very_slow'])->toBe(1);
    });

    it('aggregates data for a specific date', function () {
        // Create some data for today
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-agg',
        ]);

        WidgetConversation::create([
            'chatbot_id' => $this->chatbot->id,
            'widget_session_id' => $session->id,
            'message_count' => 5,
            'rating' => 4,
            'is_resolved' => true,
            'total_response_time_ms' => 3000,
        ]);

        // Run aggregation
        $this->analyticsService->aggregateForDate($this->chatbot, Carbon::today());

        // Check that analytics record was created
        $analytics = ChatbotAnalytics::where('chatbot_id', $this->chatbot->id)
            ->whereDate('date', Carbon::today())
            ->whereNull('hour')
            ->first();

        expect($analytics)->not->toBeNull()
            ->and($analytics->total_conversations)->toBe(1)
            ->and($analytics->total_messages)->toBe(5);
    });

    it('marks abandoned conversations correctly', function () {
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-abandon',
        ]);

        // Create a conversation that should be marked as abandoned
        $conversation = WidgetConversation::create([
            'chatbot_id' => $this->chatbot->id,
            'widget_session_id' => $session->id,
            'message_count' => 1,
            'is_resolved' => false,
            'is_abandoned' => false,
        ]);

        // Add a user message (last message from user)
        WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'Hello?',
        ]);

        // Manually update updated_at using raw query to bypass timestamp auto-update
        \DB::table('widget_conversations')
            ->where('id', $conversation->id)
            ->update(['updated_at' => now()->subHours(1)]);

        // Mark abandoned conversations
        $count = $this->analyticsService->markAbandonedConversations();

        expect($count)->toBe(1);

        $conversation->refresh();
        expect($conversation->is_abandoned)->toBeTrue()
            ->and($conversation->abandoned_at)->not->toBeNull();
    });

    it('does not mark resolved conversations as abandoned', function () {
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-resolved',
        ]);

        $conversation = WidgetConversation::create([
            'chatbot_id' => $this->chatbot->id,
            'widget_session_id' => $session->id,
            'message_count' => 2,
            'is_resolved' => true,
            'is_abandoned' => false,
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
        ]);

        WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'Thanks!',
            'created_at' => now()->subHours(1),
        ]);

        $count = $this->analyticsService->markAbandonedConversations();

        expect($count)->toBe(0);

        $conversation->refresh();
        expect($conversation->is_abandoned)->toBeFalse();
    });

    it('calculates comparison between periods', function () {
        $session = WidgetSession::create([
            'chatbot_id' => $this->chatbot->id,
            'session_token' => 'test-token-compare',
        ]);

        $comparison = $this->analyticsService->getComparison(
            $this->chatbot,
            now()->subDays(7),
            now(),
            now()->subDays(14),
            now()->subDays(8)
        );

        expect($comparison)->toHaveKeys(['current', 'previous', 'changes'])
            ->and($comparison['current'])->toHaveKey('total_conversations')
            ->and($comparison['previous'])->toHaveKey('total_conversations')
            ->and($comparison['changes'])->toHaveKeys(['conversations', 'messages', 'resolution_rate', 'avg_rating']);
    });
});

describe('aggregate command', function () {
    it('aggregates analytics for all chatbots', function () {
        $this->artisan('chatbot:aggregate-analytics')
            ->assertSuccessful();
    });

    it('aggregates analytics for a specific chatbot', function () {
        $this->artisan('chatbot:aggregate-analytics', [
            '--chatbot' => $this->chatbot->id,
        ])
            ->assertSuccessful();
    });

    it('can mark abandoned conversations', function () {
        $this->artisan('chatbot:aggregate-analytics', [
            '--mark-abandoned' => true,
        ])
            ->assertSuccessful();
    });

    it('accepts a custom date', function () {
        $this->artisan('chatbot:aggregate-analytics', [
            '--date' => '2024-01-15',
        ])
            ->assertSuccessful();
    });

    it('fails for non-existent chatbot', function () {
        $this->artisan('chatbot:aggregate-analytics', [
            '--chatbot' => 'chatbot_nonexistent',
        ])
            ->assertFailed();
    });
});
