<?php

use App\Jobs\RunRuntimeAgentJob;
use App\Models\App;
use App\Models\RuntimeAgentConversation;
use App\Models\RuntimeAgentMessage;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Runtime\RuntimeAgentService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Builder power #3, read slice 1b — the runtime agent HTTP surface: end-users
 * start a conversation and send messages to a built app's embedded agent, which
 * is answered by a background job (streamed over Reverb). The agent must be
 * enabled in the manifest and everything is tenant-scoped.
 */
function ra_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function ra_publish(App $app, User $user, bool $agentEnabled): void
{
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Agent App',
        'version' => 1,
        'objects' => [[
            'id' => ra_id('obj'),
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [['id' => ra_id('fld'), 'slug' => 'name', 'name' => 'Name', 'type' => 'string']],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => ra_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
        'agent' => [
            'enabled' => $agentEnabled,
            'name' => 'Assistant',
            'instructions' => 'Help with deals.',
            'capabilities' => ['read' => 'all', 'write' => []],
            'autonomy' => 'propose',
        ],
    ], $user);
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->agentApp = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'agent_app', 'visibility' => 'private']);
    ra_publish($this->agentApp, $this->user, agentEnabled: true);
});

it('redirects guests away from the agent endpoints', function () {
    $this->post('/r/agent_app/agent/messages', [])->assertRedirect('/login');
});

it('404s when the app does not exist', function () {
    $this->actingAs($this->user)
        ->postJson('/r/ghost/agent/conversations', [])
        ->assertNotFound();
});

it('404s when the app has no enabled agent', function () {
    $noAgent = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'plain_app', 'visibility' => 'private']);
    ra_publish($noAgent, $this->user, agentEnabled: false);

    $this->actingAs($this->user)
        ->postJson('/r/plain_app/agent/conversations', [])
        ->assertNotFound();
});

it('starts a conversation', function () {
    $response = $this->actingAs($this->user)->postJson('/r/agent_app/agent/conversations', []);

    $response->assertOk()->assertJsonPath('messages', []);
    expect($response->json('conversation_id'))->toStartWith('rconv_');
    expect(RuntimeAgentConversation::query()->where('app_id', $this->agentApp->id)->count())->toBe(1);
});

it('persists the user turn + a streaming placeholder and enqueues the job', function () {
    Queue::fake();

    $conversation = app(RuntimeAgentService::class)
        ->startConversation($this->agentApp, $this->user);

    $response = $this->actingAs($this->user)->postJson('/r/agent_app/agent/messages', [
        'conversation_id' => $conversation->id,
        'message' => 'How many deals are there?',
    ]);

    $response->assertOk()
        ->assertJsonPath('streaming', true)
        ->assertJsonPath('conversation_id', $conversation->id);

    $messages = RuntimeAgentMessage::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('How many deals are there?')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->status)->toBe('streaming');

    Queue::assertPushed(RunRuntimeAgentJob::class, fn (RunRuntimeAgentJob $job) => $job->placeholderMessageId === $messages[1]->id
        && $job->userText === 'How many deals are there?');
});

it('404s when the conversation does not exist for this app/user', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson('/r/agent_app/agent/messages', ['conversation_id' => 'rconv_doesnotexist', 'message' => 'hi'])
        ->assertNotFound();

    Queue::assertNotPushed(RunRuntimeAgentJob::class);
});
