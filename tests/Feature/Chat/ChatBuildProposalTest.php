<?php

use App\Ai\ChatAgent;
use App\Ai\Tools\Chat\ProposeBuildTool;
use App\Enums\DocumentType;
use App\Events\Chat\ChatActionExecuted;
use App\Events\Chat\ChatActionProposalReady;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\App;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\User;
use App\Services\Chat\Actions\ActionRegistry;
use App\Services\Chat\ChatAiService;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('persists a build proposal card and surfaces it live', function () {
    Event::fake([ChatActionProposalReady::class]);
    $chat = Chat::factory()->forUser($this->user)->create();

    $tool = new ProposeBuildTool($chat, $this->user);
    $reply = $tool->handle(new ToolRequest([
        'action_type' => 'create_app',
        'action_label' => 'Create the Support Desk app',
        'summary' => 'I can set this up as an app to track your customers.',
        'parameters' => ['name' => 'Support Desk'],
    ]));

    expect((string) $reply)->toContain('Support Desk');

    $proposal = ChatMessage::where('chat_id', $chat->id)
        ->where('message_type', 'action_proposal')->sole();

    expect($proposal->action_payload['action_type'])->toBe('create_app')
        ->and($proposal->action_payload['status'])->toBe('ready')
        ->and($proposal->action_payload['executable'])->toBeTrue()
        // slug is derived from the name when omitted.
        ->and($proposal->action_payload['parameters']['slug'])->toBe('support_desk');

    Event::assertDispatched(ChatActionProposalReady::class);
});

it('rejects an unknown build type', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $reply = (new ProposeBuildTool($chat, $this->user))->handle(new ToolRequest([
        'action_type' => 'delete_everything',
        'action_label' => 'x',
        'summary' => 'y',
        'parameters' => [],
    ]));

    expect((string) $reply)->toStartWith('Error:');
    expect(ChatMessage::where('chat_id', $chat->id)->count())->toBe(0);
});

it('executes a create_app proposal, building the app as the owner', function () {
    Event::fake([ChatActionExecuted::class]);
    $chat = Chat::factory()->forUser($this->user)->create();
    $proposal = ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'action_proposal',
        'action_payload' => [
            'action_type' => 'create_app',
            'action_label' => 'Create the Support Desk app',
            'summary' => 'Track customers.',
            'agreed_by' => [],
            'parameters' => ['name' => 'Support Desk', 'slug' => 'support_desk'],
            'rationale' => '',
            'executable' => true,
            'status' => 'ready',
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $proposal]))
        ->assertOk()
        ->assertJsonPath('message.message_type', 'action_result');

    expect(App::query()->where('slug', 'support_desk')->exists())->toBeTrue()
        ->and($proposal->refresh()->action_payload['status'])->toBe('executed')
        // A single-turn proposal leaves the chat-level synthesis status untouched.
        ->and($chat->refresh()->synthesis_status)->toBeNull();

    Event::assertDispatched(ChatActionExecuted::class);
});

it('locks each proposal independently and refuses a second execution', function () {
    Event::fake([ChatActionExecuted::class]);
    $chat = Chat::factory()->forUser($this->user)->create();

    $makeProposal = fn (string $slug) => ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'action_proposal',
        'action_payload' => [
            'action_type' => 'create_app',
            'action_label' => 'Create '.$slug,
            'summary' => 's',
            'agreed_by' => [],
            'parameters' => ['name' => $slug, 'slug' => $slug],
            'rationale' => '',
            'executable' => true,
            'status' => 'ready',
        ],
    ]);

    $first = $makeProposal('app_one');
    $second = $makeProposal('app_two');

    $this->actingAs($this->user)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $first]))
        ->assertOk();

    // The other proposal in the same chat stays actionable.
    expect($second->refresh()->action_payload['status'])->toBe('ready');

    // Re-executing the first is blocked by its per-message status.
    $this->actingAs($this->user)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $first]))
        ->assertStatus(422);
});

it('proposes and saves an HTML document the user keeps', function () {
    Event::fake([ChatActionProposalReady::class, ChatActionExecuted::class]);
    $chat = Chat::factory()->forUser($this->user)->create();

    $reply = (new ProposeBuildTool($chat, $this->user))->handle(new ToolRequest([
        'action_type' => 'save_document',
        'action_label' => 'Save the Q3 report',
        'summary' => 'I can save this write-up as a document you can keep.',
        'parameters' => [
            'name' => 'Q3 Report',
            'body' => '<h1>Q3</h1><p>Great quarter.</p>',
            'type' => 'artifact',
        ],
    ]));

    expect((string) $reply)->toContain('Save the Q3 report');

    $proposal = ChatMessage::where('chat_id', $chat->id)
        ->where('message_type', 'action_proposal')->sole();
    expect($proposal->action_payload['action_type'])->toBe('save_document')
        ->and($proposal->action_payload['executable'])->toBeTrue();

    $this->actingAs($this->user)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $proposal]))
        ->assertOk()
        ->assertJsonPath('message.message_type', 'action_result');

    $doc = Document::query()->where('name', 'Q3 Report')->sole();
    expect($doc->type)->toBe(DocumentType::Artifact)
        ->and($doc->body)->toContain('Great quarter')
        // Inline document: content in the body column, no file on disk.
        ->and($doc->file_path)->toBeNull();
});

it('knows the scaffold_app and save_document build types', function () {
    $registry = app(ActionRegistry::class);
    expect($registry->knows('scaffold_app'))->toBeTrue()
        ->and($registry->knows('save_document'))->toBeTrue()
        ->and(ProposeBuildTool::BUILD_TYPES)
        ->toContain('scaffold_app')
        ->toContain('save_document');
});

it('proposes a scaffold_app project tracker without building it', function () {
    Event::fake([ChatActionProposalReady::class]);
    $chat = Chat::factory()->forUser($this->user)->create();

    $reply = (new ProposeBuildTool($chat, $this->user))->handle(new ToolRequest([
        'action_type' => 'scaffold_app',
        'action_label' => 'Build a project tracker',
        'summary' => 'I can set up an app to track this plan with a Gantt.',
        'parameters' => [
            'name' => 'Launch Plan',
            'description' => 'A Tasks object with a start date, end date and status to track the launch plan.',
        ],
    ]));

    expect((string) $reply)->toContain('Build a project tracker');

    $proposal = ChatMessage::where('chat_id', $chat->id)
        ->where('message_type', 'action_proposal')->sole();
    expect($proposal->action_payload['action_type'])->toBe('scaffold_app')
        ->and($proposal->action_payload['executable'])->toBeTrue()
        ->and($proposal->action_payload['status'])->toBe('ready');

    Event::assertDispatched(ChatActionProposalReady::class);
});

it('exposes propose_build to a plain chat but not to a selected agent', function () {
    Ai::fakeAgent(ChatAgent::class, ['ok']);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);
    app(ChatAiService::class)->streamMessage($placeholder, 'I need to track customers.', null);

    Ai::assertAgentWasPrompted(ChatAgent::class, function ($prompt) {
        $names = collect($prompt->agent->tools())->map(fn ($t) => class_basename($t));

        return $names->contains('propose_build');
    });
});

it('does not expose propose_build when a selected agent runs the turn', function () {
    Ai::fakeAgent(ChatAgent::class, ['ok']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'prompt_template' => 'You are the council agent.',
    ]);
    $chat = Chat::factory()->forUser($this->user)->create(['agent_id' => $agent->id]);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);
    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    Ai::assertAgentWasPrompted(ChatAgent::class, function ($prompt) {
        $names = collect($prompt->agent->tools())->map(fn ($t) => class_basename($t));

        return ! $names->contains('propose_build');
    });
});
