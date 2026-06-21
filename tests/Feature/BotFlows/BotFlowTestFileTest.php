<?php

use App\Models\BotFlow;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

/** start → condition(file_type_is _last_upload) → image|other messages. */
function fileRoutingTestFlow(User $user): BotFlow
{
    return BotFlow::factory()->create([
        'user_id' => $user->id,
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'cond', 'type' => 'condition', 'data' => ['match_type' => 'file_type_is', 'variable' => '_last_upload', 'rules' => [['id' => 'r_img', 'pattern' => 'image']]]],
                ['id' => 'img', 'type' => 'message', 'data' => ['message' => 'Nice picture']],
                ['id' => 'other', 'type' => 'message', 'data' => ['message' => 'Document received']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'cond'],
                ['id' => 'e2', 'source' => 'cond', 'target' => 'img', 'sourceHandle' => 'r_img'],
                ['id' => 'e3', 'source' => 'cond', 'target' => 'other', 'sourceHandle' => 'default'],
            ],
        ],
    ]);
}

it('routes a simulated image upload through the test endpoint', function () {
    $flow = fileRoutingTestFlow($this->user);

    $start = $this->actingAs($this->user)
        ->postJson(route('bot-flows.test.start', $flow))
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson(route('bot-flows.test.send', $flow), [
            'state' => $start->json('state'),
            'attachments' => [['original_name' => 'photo.png', 'mime' => 'image/png']],
        ])
        ->assertOk()
        ->assertJsonPath('messages.0.content', 'Nice picture');
});

it('routes a simulated document upload to the default branch', function () {
    $flow = fileRoutingTestFlow($this->user);

    $start = $this->actingAs($this->user)
        ->postJson(route('bot-flows.test.start', $flow))
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson(route('bot-flows.test.send', $flow), [
            'state' => $start->json('state'),
            'attachments' => [['original_name' => 'invoice.pdf', 'mime' => 'application/pdf']],
        ])
        ->assertOk()
        ->assertJsonPath('messages.0.content', 'Document received');
});
