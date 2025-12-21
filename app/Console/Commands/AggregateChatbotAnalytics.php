<?php

namespace App\Console\Commands;

use App\Models\Chatbot;
use App\Services\ChatbotAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateChatbotAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chatbot:aggregate-analytics
                            {--date= : The date to aggregate (default: yesterday)}
                            {--chatbot= : Specific chatbot ID to aggregate}
                            {--mark-abandoned : Also mark abandoned conversations}';

    /**
     * The console command description.
     */
    protected $description = 'Aggregate chatbot analytics for a specific date';

    /**
     * Execute the console command.
     */
    public function handle(ChatbotAnalyticsService $analyticsService): int
    {
        $dateString = $this->option('date');
        $chatbotId = $this->option('chatbot');
        $markAbandoned = $this->option('mark-abandoned');

        // Parse date
        $date = $dateString
            ? Carbon::parse($dateString)
            : Carbon::yesterday();

        $this->info("Aggregating analytics for {$date->toDateString()}...");

        if ($chatbotId) {
            // Aggregate for specific chatbot
            $chatbot = Chatbot::find($chatbotId);

            if (! $chatbot) {
                $this->error("Chatbot not found: {$chatbotId}");

                return self::FAILURE;
            }

            $this->info("Processing chatbot: {$chatbot->name}");
            $analyticsService->aggregateForDate($chatbot, $date);
            $this->info('  - Done');
            $count = 1;
        } else {
            // Aggregate for all chatbots
            $count = $analyticsService->aggregateAllForDate($date);
            $this->info("Processed {$count} chatbots.");
        }

        // Mark abandoned conversations
        if ($markAbandoned) {
            $this->info('Marking abandoned conversations...');
            $abandoned = $analyticsService->markAbandonedConversations();
            $this->info("  - Marked {$abandoned} conversations as abandoned");
        }

        $this->info('Analytics aggregation complete.');

        return self::SUCCESS;
    }
}
