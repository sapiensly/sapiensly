<?php

use App\Jobs\ExpressDashboardJob;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\BuildExpressDashboardTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\PipelineRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['express.enabled' => true]);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
});

it('build_express_dashboard opens a run, persists the turn and queues the job', function () {
    Queue::fake();

    SapiensServer::actingAs($this->user)
        ->tool(BuildExpressDashboardTool::class, [
            'app_slug' => $this->testApp->slug,
            'prompt' => 'An operations view of delivery performance and incidents.',
        ])
        ->assertOk()
        ->assertSee('streaming');

    // A conversation was opened for the app, and both turns (user + streaming
    // assistant placeholder) were persisted.
    $conversation = BuilderConversation::query()->where('app_id', $this->testApp->id)->firstOrFail();
    expect(BuilderMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);

    // The pipeline run is open and the async build was queued against it.
    $run = PipelineRun::query()->where('app_id', $this->testApp->id)->firstOrFail();
    expect($run->kind)->toBe('dashboard_express')
        ->and($run->prompt)->toBe('An operations view of delivery performance and incidents.');

    Queue::assertPushed(ExpressDashboardJob::class, fn ($job) => $job->runId === $run->id
        && $job->prompt === 'An operations view of delivery performance and incidents.');
});

it('is gated behind the express flag', function () {
    config(['express.enabled' => false]);
    Queue::fake();

    SapiensServer::actingAs($this->user)
        ->tool(BuildExpressDashboardTool::class, [
            'app_slug' => $this->testApp->slug,
            'prompt' => 'anything',
        ])
        ->assertHasErrors();

    Queue::assertNotPushed(ExpressDashboardJob::class);
});

it('requires either an app or a conversation to target', function () {
    Queue::fake();

    SapiensServer::actingAs($this->user)
        ->tool(BuildExpressDashboardTool::class, ['prompt' => 'a dashboard'])
        ->assertHasErrors();

    Queue::assertNotPushed(ExpressDashboardJob::class);
});
