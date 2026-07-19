<?php

use App\Events\Chat\ChatStreamComplete;
use App\Jobs\ExpressAppJob;
use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\App;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Express\ExpressIntentRouter;
use App\Services\Express\ExpressLauncher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
    config(['express.enabled' => true, 'express.app_autoroute' => true]);
});

it('autoroutes an app-build chat message to the async app builder', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'crea una app para gestionar el inventario de productos'])
        ->assertCreated()
        ->assertJsonPath('placeholder.role', 'assistant')
        ->assertJsonPath('placeholder.status', 'complete');

    // The build runs the real builder turn, not the free-form chat turn — and
    // needs NO live MCP source (an app carries its own data).
    Queue::assertPushed(ExpressAppJob::class);
    Queue::assertNotPushed(RunChatAiJob::class);

    // A fresh app + first version were provisioned and an app_express run opened,
    // linked back to the chat message so the job can flip it on completion.
    $app = App::query()->where('user_id', $this->user->id)->firstOrFail();
    $assistant = ChatMessage::query()->where('chat_id', $chat->id)->where('role', 'assistant')->firstOrFail();
    $run = PipelineRun::query()->where('app_id', $app->id)->where('kind', 'app_express')->firstOrFail();
    expect($run->chat_id)->toBe($chat->id)
        ->and($run->chat_message_id)->toBe($assistant->id);

    // The chat answer is an in-progress card that tells the user to keep chatting.
    expect($assistant->content)->toContain($app->name)
        ->and($assistant->content)->toContain('te avisaré')
        ->and($assistant->content)->toContain('app');
});

it('keeps the conversational path when app_autoroute is off', function () {
    config(['express.app_autoroute' => false]);
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'crea una app para gestionar el inventario de productos'])
        ->assertCreated()
        ->assertJsonPath('placeholder.status', 'pending');

    Queue::assertPushed(RunChatAiJob::class);
    Queue::assertNotPushed(ExpressAppJob::class);
    expect(App::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('does not hijack a plain dashboard ask into the app builder', function () {
    // A clean dashboard ask (no live MCP source here, so the dashboard route
    // itself stands down) must NOT fall through to the app builder — the two
    // heuristics are disjoint on the dashboard intent.
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'crea un dashboard de ventas con KPIs'])
        ->assertCreated();

    Queue::assertPushed(RunChatAiJob::class);
    Queue::assertNotPushed(ExpressAppJob::class);
});

it('does not autoroute an attachment turn even with app-build intent', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();
    $attachment = ChatAttachment::factory()->create([
        'chat_id' => $chat->id, 'user_id' => $this->user->id, 'chat_message_id' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'crea una app de inventario con esto',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertCreated();

    Queue::assertPushed(RunChatAiJob::class);
    Queue::assertNotPushed(ExpressAppJob::class);
});

it('words the in-progress card as "tu landing" when the ask is a landing', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'créame una landing para mi SaaS de logística'])
        ->assertCreated();

    Queue::assertPushed(ExpressAppJob::class);
    $assistant = ChatMessage::query()->where('chat_id', $chat->id)->where('role', 'assistant')->firstOrFail();
    expect($assistant->content)->toContain('tu landing')
        ->and($assistant->content)->not->toContain('tu app');
});

it('announces "tu landing está lista" when the built app is a landing', function () {
    Event::fake([ChatStreamComplete::class]);
    $app = App::factory()->create(['user_id' => $this->user->id, 'kind' => 'landing']);
    $chat = Chat::factory()->forUser($this->user)->create();
    $message = ChatMessage::create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'status' => 'complete', 'message_type' => 'text',
        'content' => '⏳ Estoy construyendo tu landing…',
    ]);
    $run = PipelineRun::create([
        'app_id' => $app->id, 'kind' => 'app_express', 'status' => 'succeeded',
        'chat_id' => $chat->id, 'chat_message_id' => $message->id,
    ]);

    app(ExpressLauncher::class)->notifyChatReady($run, $app);

    expect($message->refresh()->content)->toContain('Tu landing')
        ->and($message->content)->toContain('está lista');
});

it('flips the linked chat message to "app lista" and rebroadcasts it on success', function () {
    Event::fake([ChatStreamComplete::class]);
    $app = App::factory()->create(['user_id' => $this->user->id]);
    $chat = Chat::factory()->forUser($this->user)->create();
    $message = ChatMessage::create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'status' => 'complete', 'message_type' => 'text',
        'content' => '⏳ Estoy construyendo tu app — te avisaré cuando esté lista.',
    ]);
    $run = PipelineRun::create([
        'app_id' => $app->id, 'kind' => 'app_express', 'status' => 'succeeded',
        'chat_id' => $chat->id, 'chat_message_id' => $message->id,
    ]);

    app(ExpressLauncher::class)->notifyChatReady($run, $app);

    expect($message->refresh()->content)->toContain('Tu app')
        ->and($message->content)->toContain('está lista')
        ->and($message->content)->toContain($app->name)
        ->and($message->content)->toContain(route('apps.runtime', ['app_slug' => $app->slug]));
    Event::assertDispatched(ChatStreamComplete::class);
});

it('flips the linked chat message to an honest failure when the app run did not succeed', function () {
    Event::fake([ChatStreamComplete::class]);
    $app = App::factory()->create(['user_id' => $this->user->id]);
    $chat = Chat::factory()->forUser($this->user)->create();
    $message = ChatMessage::create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'status' => 'complete', 'message_type' => 'text',
        'content' => '⏳ Estoy construyendo tu app…',
    ]);
    $run = PipelineRun::create([
        'app_id' => $app->id, 'kind' => 'app_express', 'status' => 'failed',
        'chat_id' => $chat->id, 'chat_message_id' => $message->id,
    ]);

    app(ExpressLauncher::class)->notifyChatReady($run, $app);

    expect($message->refresh()->content)->toContain('No pude terminar la app')
        ->and($message->content)->toContain($app->name);
    Event::assertDispatched(ChatStreamComplete::class);
});

it('detects app-build intent and stands down for dashboards, questions and opt-outs', function () {
    $router = app(ExpressIntentRouter::class);
    $user = User::factory()->make();

    // Positive: build verb + app noun / data-model spec.
    expect($router->shouldBuildAppForUser('crea una app para gestionar proyectos', $user))->toBeTrue()
        ->and($router->shouldBuildAppForUser('construye una aplicación de tickets de soporte', $user))->toBeTrue()
        ->and($router->shouldBuildAppForUser('quiero un sistema con objetos productos y clientes y sus relaciones', $user))->toBeTrue()
        ->and($router->shouldBuildAppForUser('créame una app de inventario', $user))->toBeTrue();

    // Landing asks ride the same handoff (the builder's landing rule + design
    // gate take over inside the turn).
    expect($router->shouldBuildAppForUser('créame una landing para mi SaaS de logística', $user))->toBeTrue()
        ->and($router->shouldBuildAppForUser('quiero una página de aterrizaje para el lanzamiento', $user))->toBeTrue();

    // Negative: a clean dashboard ask, a question, a process opt-out, no verb.
    expect($router->shouldBuildAppForUser('crea un dashboard de ventas con KPIs', $user))->toBeFalse()
        ->and($router->shouldBuildAppForUser('¿cómo creo una app?', $user))->toBeFalse()
        ->and($router->shouldBuildAppForUser('explícame cómo hacer una app paso a paso', $user))->toBeFalse()
        ->and($router->shouldBuildAppForUser('me gusta esta app', $user))->toBeFalse();
});

it('respects the app_autoroute flag in the router', function () {
    config(['express.app_autoroute' => false]);
    $router = app(ExpressIntentRouter::class);
    expect($router->shouldBuildAppForUser('crea una app para gestionar proyectos', User::factory()->make()))->toBeFalse();
});
