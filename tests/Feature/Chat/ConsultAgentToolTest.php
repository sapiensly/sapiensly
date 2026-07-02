<?php

use App\Ai\RuntimeAgent;
use App\Ai\Tools\Chat\ConsultAgentTool;
use App\Enums\AgentStatus;
use App\Events\Chat\ChatAgentConsultation;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\LLMService;
use App\Support\Chat\ConsultationLog;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Tools\Request as AiRequest;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->current = Agent::factory()->create(['user_id' => $this->user->id, 'name' => 'Main', 'status' => AgentStatus::Active]);
    $this->target = Agent::factory()->create(['user_id' => $this->user->id, 'name' => 'Knowledge', 'status' => AgentStatus::Active]);
    $this->chat = Chat::factory()->create(['user_id' => $this->user->id]);
    $this->placeholder = ChatMessage::factory()->create(['chat_id' => $this->chat->id, 'role' => 'assistant', 'status' => 'streaming']);
    $this->log = new ConsultationLog;
});

function consultTool($test): ConsultAgentTool
{
    return new ConsultAgentTool($test->chat, $test->placeholder, $test->user, $test->current, $test->log);
}

it('consults another agent, broadcasts the exchange, and logs it', function () {
    Event::fake([ChatAgentConsultation::class]);

    // The consulted turn is streamed (chatStreamed), so a slow reasoning agent
    // rides the SSE idle watchdog instead of the SDK's short blocking timeout.
    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('setContext')->andReturnSelf();
    $llm->shouldReceive('chatStreamed')->once()->andReturn('Yes, order #1234 is eligible.');
    $this->app->instance(LLMService::class, $llm);

    $out = (string) consultTool($this)->handle(new AiRequest([
        'agent_id' => $this->target->id,
        'question' => 'Is order #1234 eligible for a refund?',
        'visible' => true,
    ]));

    expect($out)->toContain('Knowledge', 'Yes, order #1234 is eligible.');

    // Logged for persistence.
    expect($this->log->all())->toHaveCount(1);
    expect($this->log->all()[0]['answer'])->toBe('Yes, order #1234 is eligible.');
    expect($this->log->all()[0]['visible'])->toBeTrue();

    // Live feedback: a start and a result event.
    Event::assertDispatchedTimes(ChatAgentConsultation::class, 2);
});

it('streams the consulted agent answer live via delta events', function () {
    Event::fake([ChatAgentConsultation::class]);
    // A long-enough answer to flush more than one live batch to the card.
    Ai::fakeAgent(RuntimeAgent::class, ['This is a sufficiently long consulted answer so it streams to the card in more than one batch.']);

    $out = (string) consultTool($this)->handle(new AiRequest([
        'agent_id' => $this->target->id,
        'question' => 'What is our positioning?',
        'visible' => true,
    ]));

    expect($out)->toContain('sufficiently long consulted answer');

    // The card is opened (start), written into live (delta), then finalized (result).
    Event::assertDispatched(ChatAgentConsultation::class, fn ($e) => $e->phase === 'start');
    Event::assertDispatched(ChatAgentConsultation::class, fn ($e) => $e->phase === 'delta' && $e->answer !== null && $e->answer !== '');
    Event::assertDispatched(ChatAgentConsultation::class, fn ($e) => $e->phase === 'result' && str_contains((string) $e->answer, 'sufficiently long'));
});

it('refuses to consult beyond the per-turn cap', function () {
    // Fill the turn's consultation log up to the cap (3).
    for ($i = 0; $i < 3; $i++) {
        $this->log->add(['id' => (string) $i]);
    }

    // The expensive streamed consult must not run once the cap is reached.
    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('chatStreamed')->never();
    $this->app->instance(LLMService::class, $llm);

    $out = (string) consultTool($this)->handle(new AiRequest([
        'agent_id' => $this->target->id,
        'question' => 'One more question',
    ]));

    expect($out)->toContain('consultation limit reached');
    expect($this->log->all())->toHaveCount(3);
});

it('refuses to let an agent consult itself', function () {
    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('chatStreamed')->never();
    $this->app->instance(LLMService::class, $llm);

    $out = (string) consultTool($this)->handle(new AiRequest([
        'agent_id' => $this->current->id,
        'question' => 'anything',
    ]));

    expect($out)->toContain('cannot consult yourself');
    expect($this->log->all())->toBeEmpty();
});

it('reports an unknown or out-of-scope agent', function () {
    $other = Agent::factory()->create(); // a different account's agent

    $out = (string) consultTool($this)->handle(new AiRequest([
        'agent_id' => $other->id,
        'question' => 'anything',
    ]));

    expect($out)->toContain('no agent');
    expect($this->log->all())->toBeEmpty();
});
