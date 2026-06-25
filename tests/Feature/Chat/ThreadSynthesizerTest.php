<?php

use App\Ai\ChatAgent;
use App\Events\Chat\ChatActionProposalReady;
use App\Jobs\Chat\SynthesizeThread;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\ThreadSynthesizer;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

function multiAgentChat(User $user): Chat
{
    $chat = Chat::factory()->forUser($user)->create(['mode' => 'multi_agent']);
    $agent = Agent::factory()->forUser($user)->active()->create(['name' => 'Finance']);
    ChatParticipant::create(['chat_id' => $chat->id, 'agent_id' => $agent->id, 'joined_at' => now()]);

    ChatMessage::factory()->create([
        'chat_id' => $chat->id, 'role' => 'user', 'content' => 'Launch ads?', 'status' => 'complete',
    ]);
    ChatMessage::factory()->create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'agent_id' => $agent->id,
        'content' => 'Yes, within budget.', 'status' => 'complete',
    ]);

    return $chat;
}

it('persists an action proposal and marks the thread ready', function () {
    Event::fake([ChatActionProposalReady::class]);
    Ai::fakeAgent(ChatAgent::class, [
        '{"action_type":"launch_campaign","action_label":"Launch Q3 ads","agreed_by":["Finance"],"parameters":{"budget":"$2000"},"rationale":"Within budget"}',
    ]);

    $chat = multiAgentChat($this->user);
    app(ThreadSynthesizer::class)->synthesize($chat);

    $proposal = ChatMessage::where('chat_id', $chat->id)
        ->where('message_type', 'action_proposal')
        ->firstOrFail();

    expect($proposal->action_payload['action_label'])->toBe('Launch Q3 ads')
        // unknown action_type normalizes to the manual handler in v1
        ->and($proposal->action_payload['action_type'])->toBe('manual')
        ->and($chat->refresh()->synthesis_status)->toBe('ready');

    Event::assertDispatched(ChatActionProposalReady::class);
});

it('falls back to a dismissed system message when nothing is actionable', function () {
    Event::fake([ChatActionProposalReady::class]);
    Ai::fakeAgent(ChatAgent::class, [
        '{"action_type":"none","action_label":"","agreed_by":[],"parameters":{},"rationale":"No consensus"}',
    ]);

    $chat = multiAgentChat($this->user);
    app(ThreadSynthesizer::class)->synthesize($chat);

    expect(ChatMessage::where('chat_id', $chat->id)->where('message_type', 'action_proposal')->count())->toBe(0)
        ->and(ChatMessage::where('chat_id', $chat->id)->where('role', 'system')->count())->toBe(1)
        ->and($chat->refresh()->synthesis_status)->toBe('dismissed');

    Event::assertDispatched(ChatActionProposalReady::class);
});

it('abort closes a pending deliberation so the indicator resolves', function () {
    Event::fake([ChatActionProposalReady::class]);
    $chat = multiAgentChat($this->user);
    $chat->forceFill(['synthesis_status' => 'pending'])->save();

    app(ThreadSynthesizer::class)->abort($chat);

    expect($chat->refresh()->synthesis_status)->toBe('dismissed')
        ->and(ChatMessage::where('chat_id', $chat->id)->where('role', 'system')->exists())->toBeTrue();

    Event::assertDispatched(ChatActionProposalReady::class);
});

it('abort is a no-op once the thread already reached a terminal state', function () {
    Event::fake([ChatActionProposalReady::class]);
    $chat = multiAgentChat($this->user);
    $chat->forceFill(['synthesis_status' => 'ready'])->save();

    app(ThreadSynthesizer::class)->abort($chat);

    expect($chat->refresh()->synthesis_status)->toBe('ready');
    Event::assertNotDispatched(ChatActionProposalReady::class);
});

it('SynthesizeThread::failed closes a deliberation that never synthesized', function () {
    Event::fake([ChatActionProposalReady::class]);
    $chat = multiAgentChat($this->user);
    $chat->forceFill(['synthesis_status' => 'pending'])->save();

    (new SynthesizeThread($chat->id))->failed(new RuntimeException('timed out'));

    expect($chat->refresh()->synthesis_status)->toBe('dismissed');
    Event::assertDispatched(ChatActionProposalReady::class);
});
