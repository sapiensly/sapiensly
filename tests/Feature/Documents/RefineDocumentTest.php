<?php

use App\Models\AiProvider;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('refine endpoint refuses when no AI provider is configured', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/documents/refine', [
            'type' => 'artifact',
            'instruction' => 'Make the header sticky',
            'currentBody' => '<!doctype html><html><body><h1>Hi</h1></body></html>',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);
});

test('refine endpoint validates the payload', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/documents/refine', [
            'type' => 'artifact',
            'instruction' => 'ab', // too short
            'currentBody' => '<!doctype html><html></html>',
        ])
        ->assertStatus(422);

    actingAs($user)
        ->postJson('/documents/refine', [
            'type' => 'md', // not artifact
            'instruction' => 'Make it longer',
            'currentBody' => '# hi',
        ])
        ->assertStatus(422);
});

test('refine endpoint caps history length', function () {
    $user = User::factory()->create();

    $history = array_fill(0, 21, [
        'role' => 'user',
        'content' => 'a test turn',
    ]);

    actingAs($user)
        ->postJson('/documents/refine', [
            'type' => 'artifact',
            'instruction' => 'Add a footer',
            'currentBody' => '<!doctype html><html></html>',
            'history' => $history,
        ])
        ->assertStatus(422);
});

test('refine endpoint rejects unknown history roles', function () {
    $user = User::factory()->create();
    AiProvider::create([
        'user_id' => $user->id,
        'name' => 'Default',
        'display_name' => 'Default',
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-test'],
        'models' => [
            ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini', 'capabilities' => ['chat']],
        ],
        'is_default' => true,
        'status' => 'active',
    ]);

    actingAs($user)
        ->postJson('/documents/refine', [
            'type' => 'artifact',
            'instruction' => 'Tighten the CTA copy',
            'currentBody' => '<!doctype html><html></html>',
            'history' => [
                ['role' => 'system', 'content' => 'sneaky'],
            ],
        ])
        ->assertStatus(422);
});
