<?php

use App\Enums\BotFlowActionType;
use App\Models\BotFlow;
use App\Models\User;
use App\Services\BotFlowExecutorService;

beforeEach(function () {
    $this->executor = app(BotFlowExecutorService::class);
    $this->user = User::factory()->create();
});

function fileDescriptor(string $kind = 'document', string $mime = 'application/pdf', string $name = 'invoice.pdf'): array
{
    return [
        'id' => 'watt_'.uniqid(),
        'original_name' => $name,
        'mime' => $mime,
        'kind' => $kind,
        'disk' => 's3',
        'storage_path' => 'widget_uploads/x/'.$name,
        'extracted_text' => 'Total due: $42',
    ];
}

/** start → input(file) → message('captured') */
function fileInputFlow(User $user): BotFlow
{
    return BotFlow::factory()->create([
        'user_id' => $user->id,
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'ask', 'type' => 'input', 'data' => ['variable' => 'doc', 'input_type' => 'file', 'accept' => ['document'], 'prompt' => 'Upload your invoice', 'error_message' => 'Please attach a document']],
                ['id' => 'done', 'type' => 'message', 'data' => ['message' => 'Got it']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'ask'],
                ['id' => 'e2', 'source' => 'ask', 'target' => 'done'],
            ],
        ],
    ]);
}

test('file input node captures the uploaded descriptor into its variable', function () {
    $flow = fileInputFlow($this->user);

    // Start advances to the input node and prompts for a file.
    $state = $this->executor->initializeFlow($flow);
    $prompt = $this->executor->processInput($flow, $state, '');
    expect($prompt->type)->toBe(BotFlowActionType::CollectInput)
        ->and($prompt->data['input_type'])->toBe('file');

    // Supplying a document captures it and advances.
    $file = fileDescriptor();
    $action = $this->executor->processInput($flow, $prompt->updatedState, '', [$file]);

    expect($action->type)->toBe(BotFlowActionType::SendMessage)
        ->and($action->updatedState['variables']['doc'])->toHaveCount(1)
        ->and($action->updatedState['variables']['doc'][0]['original_name'])->toBe('invoice.pdf')
        // Light descriptor — extracted_text is stripped from persisted state.
        ->and($action->updatedState['variables']['doc'][0])->not->toHaveKey('extracted_text');
});

test('file input re-prompts when the attachment is not an accepted type', function () {
    $flow = fileInputFlow($this->user);

    $state = $this->executor->initializeFlow($flow);
    $prompt = $this->executor->processInput($flow, $state, '');

    // An image when the node only accepts documents → re-prompt, no advance.
    $image = fileDescriptor('image', 'image/png', 'photo.png');
    $action = $this->executor->processInput($flow, $prompt->updatedState, '', [$image]);

    expect($action->type)->toBe(BotFlowActionType::CollectInput)
        ->and($action->data['invalid'])->toBeTrue();
});

/** start → condition(file_type_is on _last_upload) → image|other */
function fileConditionFlow(User $user): BotFlow
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

test('condition routes on file type', function () {
    $flow = fileConditionFlow($this->user);

    // Start advances to the (waiting) condition node.
    $state = $this->executor->initializeFlow($flow);
    $waiting = $this->executor->processInput($flow, $state, '');

    // Upload an image → routes down the image branch.
    $imageTurn = $this->executor->processInput($flow, $waiting->updatedState, 'here', [fileDescriptor('image', 'image/png', 'p.png')]);
    expect($imageTurn->data['message'])->toBe('Nice picture');
});

test('condition falls through to default for a non-matching file type', function () {
    $flow = fileConditionFlow($this->user);

    $state = $this->executor->initializeFlow($flow);
    $waiting = $this->executor->processInput($flow, $state, '');

    $docTurn = $this->executor->processInput($flow, $waiting->updatedState, 'here', [fileDescriptor('document', 'application/pdf', 'd.pdf')]);
    expect($docTurn->data['message'])->toBe('Document received');
});
