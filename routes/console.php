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

// Expired export files are copies of tenant data living outside the database.
// Sweeping them nightly is what keeps a download from becoming a second,
// unmanaged store of the same rows.
Schedule::command('exports:prune')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();

// Emit AI spend budget threshold alerts.
Schedule::command('ai-spend:check-budgets')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Fire schedule-triggered workflows whose cron is due this minute.
Schedule::command('flows:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Drop activity older than each app was told to keep. Nightly and off-peak:
// it is maintenance, and the default of one month means most tenants have very
// little to remove on any given night.
Schedule::command('activity:prune')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();

// Empty the trash of records that have been in it past the recovery window.
// Ten minutes after the trail prune rather than alongside it: both are batched
// deletes on the tenant schema, and there is no reason for them to contend.
Schedule::command('records:prune-trash')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

// Fire record.date_reached workflows whose date-field offset is due.
Schedule::command('flows:dispatch-date-reached')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Poll integration.poll workflows and fire on newly-seen items.
Schedule::command('flows:dispatch-polls')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Living Decks: queue data refreshes for presentations whose schedule is due.
Schedule::command('slides:refresh-due')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Resolve chat messages orphaned mid-stream (worker died without finalizing).
Schedule::command('chat:fail-stale-streams')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Same, for app-builder turns whose worker was hard-killed (deploy/OOM).
Schedule::command('builder:fail-stale-streams')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Give back any chat a person took over and then went quiet on. A visitor left
// waiting on someone who closed their laptop is the worst outcome the handoff
// can produce, and it is produced by the feature working — so the bound runs on
// a timer rather than on anyone remembering.
Schedule::command('chatbot:reclaim-unattended')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
