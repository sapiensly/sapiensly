<?php

use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\User;
use App\Services\BotFlowExecutorService;
use App\Services\TeamOrchestrationService;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function flowWithStartTrigger(User $user, string $trigger, array $extraStartData = [], array $extraNodes = [], array $edges = []): BotFlow
{
    return BotFlow::factory()->create([
        'user_id' => $user->id,
        'definition' => [
            'nodes' => array_merge(
                [['id' => 'start', 'type' => 'start', 'data' => array_merge(['trigger' => $trigger], $extraStartData)]],
                $extraNodes,
            ),
            'edges' => $edges,
        ],
    ]);
}

test('shouldActivateBotFlow honours the start trigger', function () {
    $executor = app(BotFlowExecutorService::class);

    $always = flowWithStartTrigger($this->user, 'always');
    expect($executor->shouldActivateBotFlow($always, 'anything', null))->toBeTrue();

    $onStart = flowWithStartTrigger($this->user, 'conversation_start');
    expect($executor->shouldActivateBotFlow($onStart, 'hi', null))->toBeTrue()
        ->and($executor->shouldActivateBotFlow($onStart, 'hi', ['current_node_id' => 'x', 'completed' => true]))->toBeFalse();

    $keyword = flowWithStartTrigger($this->user, 'keyword', ['keywords' => ['refund']]);
    expect($executor->shouldActivateBotFlow($keyword, 'I need a refund', null))->toBeTrue()
        ->and($executor->shouldActivateBotFlow($keyword, 'hello there', null))->toBeFalse();
});

test('shouldActivateBotFlow continues an in-progress flow regardless of trigger', function () {
    $executor = app(BotFlowExecutorService::class);
    $flow = flowWithStartTrigger($this->user, 'keyword', ['keywords' => ['refund']]);

    expect($executor->shouldActivateBotFlow($flow, 'no keyword here', ['current_node_id' => 'menu', 'completed' => false]))->toBeTrue();
});

test('orchestrateBotFlow runs the flow and emits a menu without the LLM', function () {
    $flow = flowWithStartTrigger(
        $this->user,
        'always',
        extraNodes: [
            ['id' => 'menu', 'type' => 'menu', 'data' => ['message' => 'Pick one', 'options' => [['id' => 'o1', 'label' => 'A']]]],
        ],
        edges: [['id' => 'e1', 'source' => 'start', 'target' => 'menu']],
    );

    $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

    $events = iterator_to_array(
        app(TeamOrchestrationService::class)->orchestrateBotFlow($flow, $conversation, 'hi'),
        false,
    );
    $types = array_column($events, 'type');

    expect($types)->toContain('flow_start')
        ->and($types)->toContain('flow_menu');
});
