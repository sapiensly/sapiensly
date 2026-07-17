<?php

use App\Events\Chat\ChatStreamComplete;
use App\Jobs\ExpressDashboardJob;
use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\App;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Express\ExpressLauncher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
    config(['express.enabled' => true, 'express.autoroute' => true]);
});

function chat_express_source(User $user): Integration
{
    return Integration::factory()->forUser($user)->create([
        'is_mcp' => true, 'status' => 'active', 'auth_type' => 'bearer', 'auth_config' => ['token' => 'T'],
        'base_url' => 'https://mcp.example.com/v1',
    ]);
}

it('autoroutes a dashboard-build chat message to Express instead of a chat turn', function () {
    Queue::fake();
    chat_express_source($this->user);
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'crea un dashboard de tickets con KPIs'])
        ->assertCreated()
        ->assertJsonPath('placeholder.role', 'assistant')
        ->assertJsonPath('placeholder.status', 'complete');

    // The build runs on the Express pipeline, not the free-form chat turn.
    Queue::assertPushed(ExpressDashboardJob::class);
    Queue::assertNotPushed(RunChatAiJob::class);

    // A fresh app + its first version were provisioned and a run opened, linked
    // back to the chat message so the job can flip it on completion.
    $app = App::query()->where('user_id', $this->user->id)->firstOrFail();
    $assistant = ChatMessage::query()->where('chat_id', $chat->id)->where('role', 'assistant')->firstOrFail();
    $run = PipelineRun::query()->where('app_id', $app->id)->where('kind', 'dashboard_express')->firstOrFail();
    expect($run->chat_id)->toBe($chat->id)
        ->and($run->chat_message_id)->toBe($assistant->id);

    // The chat answer is an in-progress card that tells the user to keep chatting.
    expect($assistant->content)->toContain($app->name)
        ->and($assistant->content)->toContain('te avisaré');
});

it('keeps the conversational path when autoroute is off', function () {
    config(['express.autoroute' => false]);
    Queue::fake();
    chat_express_source($this->user);
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'crea un dashboard de tickets con KPIs'])
        ->assertCreated()
        ->assertJsonPath('placeholder.status', 'pending');

    Queue::assertPushed(RunChatAiJob::class);
    Queue::assertNotPushed(ExpressDashboardJob::class);
    expect(App::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('does not autoroute without a live MCP source', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'crea un dashboard de tickets con KPIs'])
        ->assertCreated();

    Queue::assertPushed(RunChatAiJob::class);
    Queue::assertNotPushed(ExpressDashboardJob::class);
});

function chat_linked_run(User $user, App $app, string $status): array
{
    $chat = Chat::factory()->forUser($user)->create();
    $message = ChatMessage::create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'status' => 'complete', 'message_type' => 'text',
        'content' => '⏳ Estoy construyendo tu dashboard — te avisaré cuando esté listo.',
    ]);
    $run = PipelineRun::create([
        'app_id' => $app->id, 'kind' => 'dashboard_express', 'status' => $status,
        'chat_id' => $chat->id, 'chat_message_id' => $message->id,
    ]);

    return [$run, $message];
}

it('flips the linked chat message to "ready" and rebroadcasts it on success', function () {
    Event::fake([ChatStreamComplete::class]);
    $app = App::factory()->create(['user_id' => $this->user->id]);
    [$run, $message] = chat_linked_run($this->user, $app, 'succeeded');

    app(ExpressLauncher::class)->notifyChatReady($run, $app);

    expect($message->refresh()->content)->toContain('está listo')
        ->and($message->content)->toContain($app->name)
        ->and($message->content)->toContain(route('apps.runtime', ['app_slug' => $app->slug]));
    Event::assertDispatched(ChatStreamComplete::class);
});

it('flips the linked chat message to an honest failure when the run did not succeed', function () {
    Event::fake([ChatStreamComplete::class]);
    $app = App::factory()->create(['user_id' => $this->user->id]);
    [$run, $message] = chat_linked_run($this->user, $app, 'failed');

    app(ExpressLauncher::class)->notifyChatReady($run, $app);

    expect($message->refresh()->content)->toContain('No pude terminar')
        ->and($message->content)->toContain($app->name);
    Event::assertDispatched(ChatStreamComplete::class);
});

it('does nothing for a Builder-launched run with no linked chat message', function () {
    Event::fake([ChatStreamComplete::class]);
    $app = App::factory()->create(['user_id' => $this->user->id]);
    $run = PipelineRun::create(['app_id' => $app->id, 'kind' => 'dashboard_express', 'status' => 'succeeded']);

    app(ExpressLauncher::class)->notifyChatReady($run, $app);

    Event::assertNotDispatched(ChatStreamComplete::class);
});

it('does not autoroute an attachment turn even with dashboard intent', function () {
    Queue::fake();
    chat_express_source($this->user);
    $chat = Chat::factory()->forUser($this->user)->create();
    $attachment = ChatAttachment::factory()->create([
        'chat_id' => $chat->id, 'user_id' => $this->user->id, 'chat_message_id' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'crea un dashboard con esto',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertCreated();

    Queue::assertPushed(RunChatAiJob::class);
    Queue::assertNotPushed(ExpressDashboardJob::class);
});
