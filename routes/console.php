<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aggregate chatbot analytics daily at 1:00 AM
Schedule::command('chatbot:aggregate-analytics --mark-abandoned')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

// Prune integration execution history nightly.
Schedule::command('integrations:prune-executions')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// Emit AI spend budget threshold alerts.
Schedule::command('ai-spend:check-budgets')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
