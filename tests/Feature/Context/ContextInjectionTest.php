<?php

use App\Ai\ChatAgent;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\App;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Services\Apps\AppNamer;
use App\Services\Builder\BuilderAiService;
use App\Services\Chat\ChatAiService;
use App\Services\LLMService;
use App\Services\Runtime\RuntimeAgentService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;

/**
 * The Contextbook reaches every user-facing prompt-building chokepoint, and
 * nothing else. An organization without one leaves prompts byte-identical to a
 * platform without the feature.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Logistics', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'organization_id' => $this->org->id,
    ]);
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

/** Give the organization a Contextbook, compiled the way the app compiles it. */
function seedContextbook(Organization $org, array $profile = [], bool $enabled = true): OrganizationAiContext
{
    $row = OrganizationAiContext::firstOrNew(['organization_id' => $org->id])
        ->setRelation('organization', $org);

    $row->fill([
        'profile' => array_merge([
            'descriptor' => 'Moves refrigerated freight for food producers.',
            'glossary' => [['term' => 'guia', 'meaning' => 'the shipment tracking document']],
        ], $profile),
        'enabled' => $enabled,
    ])->recompile()->save();

    return $row;
}

it('grounds an internal agent run in the Contextbook, before the agent instructions', function () {
    seedContextbook($this->org);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'prompt_template' => 'You are the support agent.',
    ]);

    $method = new ReflectionMethod(app(LLMService::class), 'buildAgent');
    $instructions = $method->invoke(app(LLMService::class), $agent, [], [], null, false)->instructions();

    expect($instructions)->toStartWith('<organization_context>')
        ->toContain('Moves refrigerated freight')
        ->toContain('the shipment tracking document')
        // The agent's own instructions still come last, so they win by specificity.
        ->toContain('You are the support agent.')
        ->and(strpos($instructions, '<organization_context>'))
        ->toBeLessThan(strpos($instructions, 'You are the support agent.'));
});

it('grounds a chat turn in the Contextbook', function () {
    seedContextbook($this->org);
    Ai::fakeAgent(ChatAgent::class, ['Sure.']);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'What do we ship?', null);

    Ai::assertAgentWasPrompted(
        ChatAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), '<organization_context>')
            && str_contains($prompt->agent->instructions(), 'Moves refrigerated freight'),
    );
});

it('grounds the builder in the Contextbook so it writes with real copy', function () {
    seedContextbook($this->org);

    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);

    $service = app(BuilderAiService::class);
    $prompt = (new ReflectionMethod($service, 'systemPrompt'))->invoke($service, $app, null);

    expect($prompt)->toStartWith('<organization_context>')
        ->toContain('Moves refrigerated freight')
        ->toContain('You are the Builder AI inside Sapiensly.');
});

it('grounds an app runtime agent in the Contextbook', function () {
    seedContextbook($this->org);

    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);

    $service = app(RuntimeAgentService::class);
    $prompt = (new ReflectionMethod($service, 'systemPrompt'))
        ->invoke($service, $app, ['agent' => ['name' => 'Ada', 'instructions' => 'Help users.']]);

    expect($prompt)->toStartWith('<organization_context>')
        ->toContain('Moves refrigerated freight')
        ->toContain('You are Ada');
});

it('grounds a workflow ai.complete step in the Contextbook', function () {
    seedContextbook($this->org);
    Ai::fakeAgent(AnonymousAgent::class, ['Done.']);

    $engine = app(WorkflowEngine::class);
    (new ReflectionMethod($engine, 'handleAiComplete'))->invoke(
        $engine,
        ['type' => 'ai.complete', 'system_prompt' => 'Summarize the ticket.', 'user_prompt' => 'Ticket 1'],
        [],
        // The step runs inside an app, and its spend bills to that app.
        App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]),
        $this->user,
    );

    Ai::assertAgentWasPrompted(
        AnonymousAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), '<organization_context>')
            && str_contains($prompt->agent->instructions(), 'Summarize the ticket.'),
    );
});

it('renders the datetime line in the organization timezone', function () {
    seedContextbook($this->org, ['timezone' => 'America/Mexico_City']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
    ]);

    $method = new ReflectionMethod(app(LLMService::class), 'buildAgent');
    $instructions = $method->invoke(app(LLMService::class), $agent, [], [], null, false)->instructions();

    expect($instructions)->toContain('in America/Mexico_City')
        ->not->toContain('in UTC');
});

it('injects nothing for an organization with no Contextbook', function () {
    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'prompt_template' => 'You are the support agent.',
    ]);

    $method = new ReflectionMethod(app(LLMService::class), 'buildAgent');
    $instructions = $method->invoke(app(LLMService::class), $agent, [], [], null, false)->instructions();

    expect($instructions)->not->toContain('<organization_context>')
        ->toStartWith('CURRENT DATE/TIME');
});

it('injects nothing when the organization switched injection off', function () {
    seedContextbook($this->org, enabled: false);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
    ]);

    $method = new ReflectionMethod(app(LLMService::class), 'buildAgent');
    $instructions = $method->invoke(app(LLMService::class), $agent, [], [], null, false)->instructions();

    expect($instructions)->not->toContain('<organization_context>');
});

it('injects nothing for a personal account — it has no business identity', function () {
    seedContextbook($this->org);

    $personal = User::factory()->create(['email_verified_at' => now(), 'organization_id' => null]);
    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $personal->id,
        'organization_id' => null,
    ]);

    $method = new ReflectionMethod(app(LLMService::class), 'buildAgent');
    $instructions = $method->invoke(app(LLMService::class), $agent, [], [], null, false)->instructions();

    expect($instructions)->not->toContain('<organization_context>');
});

it('keeps the Contextbook out of one-shot utility prompts, which nobody reads as the organization', function () {
    seedContextbook($this->org);
    Ai::fakeAgent(ChatAgent::class, ['Control de Envios']);

    app(AppNamer::class)->nameFromPrompt('una app para controlar envios', $this->user);

    Ai::assertAgentWasPrompted(
        ChatAgent::class,
        fn ($prompt) => ! str_contains($prompt->agent->instructions(), '<organization_context>'),
    );
});
