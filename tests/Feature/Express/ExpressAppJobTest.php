<?php

use App\Events\Chat\ChatStreamComplete;
use App\Jobs\ExpressAppJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->appModel = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->user->organization_id, 'name' => 'Inventario', 'slug' => 'inventario']);
    $manifests = app(AppManifestService::class);
    $manifests->createVersion($this->appModel, $manifests->initialManifest($this->appModel), $this->user, 'Initial version');

    $this->conversation = BuilderConversation::create([
        'organization_id' => $this->appModel->organization_id,
        'app_id' => $this->appModel->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $this->placeholder = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant', 'content' => '', 'status' => 'streaming',
    ]);
});

function app_express_run(App $app, BuilderConversation $conversation, User $user, string $status = 'running'): array
{
    $chat = Chat::factory()->forUser($user)->create();
    $chatMessage = ChatMessage::create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'status' => 'complete', 'message_type' => 'text',
        'content' => '⏳ Estoy construyendo tu app…',
    ]);
    $run = PipelineRun::create([
        'app_id' => $app->id, 'conversation_id' => $conversation->id, 'kind' => 'app_express',
        'status' => $status, 'chat_id' => $chat->id, 'chat_message_id' => $chatMessage->id,
    ]);

    return [$run, $chatMessage];
}

it('runs a builder turn, marks the run succeeded and announces the app in the chat', function () {
    Event::fake([ChatStreamComplete::class]);
    [$run, $chatMessage] = app_express_run($this->appModel, $this->conversation, $this->user);

    // Simulate the real builder turn: it applies a version that adds an object,
    // exactly what scaffold_app would do — the job's success signal.
    $this->mock(BuilderAiService::class, function ($mock) {
        $mock->shouldReceive('streamMessage')->once()->andReturnUsing(function ($placeholder) {
            $manifests = app(AppManifestService::class);
            $fresh = App::query()->findOrFail($this->appModel->id);
            $manifest = app(AppScaffolder::class)->assemble($manifests->getActiveManifest($fresh), [
                'objects' => [['name' => 'Productos', 'slug' => 'productos', 'fields' => [
                    ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ]]],
                'links' => [],
            ]);
            $manifests->createVersion($fresh, $manifest, $this->user, 'built');

            return $placeholder;
        });
    });

    (new ExpressAppJob($this->placeholder->id, $run->id, 'crea una app de inventario'))
        ->handle(app(BuilderAiService::class), app(BuilderCancellation::class));

    expect($run->refresh()->status)->toBe('succeeded')
        ->and($run->finished_at)->not->toBeNull();

    expect($chatMessage->refresh()->content)->toContain('Tu app')
        ->and($chatMessage->content)->toContain('está lista')
        ->and($chatMessage->content)->toContain('Inventario');
    Event::assertDispatched(ChatStreamComplete::class);
});

it('marks the run failed and notifies honestly when the turn builds no objects', function () {
    Event::fake([ChatStreamComplete::class]);
    [$run, $chatMessage] = app_express_run($this->appModel, $this->conversation, $this->user);

    // A turn that couldn't apply anything leaves the manifest object-less.
    $this->mock(BuilderAiService::class, function ($mock) {
        $mock->shouldReceive('streamMessage')->once()->andReturnUsing(fn ($placeholder) => $placeholder);
    });

    (new ExpressAppJob($this->placeholder->id, $run->id, 'crea una app de inventario'))
        ->handle(app(BuilderAiService::class), app(BuilderCancellation::class));

    expect($run->refresh()->status)->toBe('failed');
    expect($chatMessage->refresh()->content)->toContain('No pude terminar la app');
    Event::assertDispatched(ChatStreamComplete::class);
});

it('stops before spending a turn when the user already cancelled', function () {
    Event::fake([ChatStreamComplete::class]);
    [$run, $chatMessage] = app_express_run($this->appModel, $this->conversation, $this->user);

    app(BuilderCancellation::class)->request($this->conversation);

    // streamMessage must never be reached.
    $this->mock(BuilderAiService::class, function ($mock) {
        $mock->shouldReceive('streamMessage')->never();
    });

    (new ExpressAppJob($this->placeholder->id, $run->id, 'crea una app de inventario'))
        ->handle(app(BuilderAiService::class), app(BuilderCancellation::class));

    expect($run->refresh()->status)->toBe('stopped');
    expect($chatMessage->refresh()->content)->toContain('No pude terminar la app');
});
