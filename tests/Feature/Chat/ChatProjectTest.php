<?php

use App\Models\ChatProject;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates a chat project', function () {
    $this->actingAs($this->user)
        ->post(route('chat-projects.store'), [
            'name' => 'Research',
            'custom_instructions' => 'Always cite sources.',
        ])
        ->assertRedirect();

    $project = ChatProject::where('user_id', $this->user->id)->firstOrFail();
    expect($project->name)->toBe('Research')
        ->and($project->custom_instructions)->toBe('Always cite sources.');
});

it('updates and deletes a project owned by the user', function () {
    $project = ChatProject::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->patch(route('chat-projects.update', ['chat_project' => $project->id]), ['name' => 'Renamed'])
        ->assertRedirect();
    expect($project->refresh()->name)->toBe('Renamed');

    $this->actingAs($this->user)
        ->delete(route('chat-projects.destroy', ['chat_project' => $project->id]))
        ->assertRedirect();
    expect(ChatProject::find($project->id))->toBeNull();
});

it('forbids editing another user\'s project', function () {
    $project = ChatProject::factory()->create();

    $this->actingAs($this->user)
        ->patch(route('chat-projects.update', ['chat_project' => $project->id]), ['name' => 'x'])
        ->assertForbidden();
});
