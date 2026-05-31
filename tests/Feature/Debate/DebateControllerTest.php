<?php

use App\Models\AiProvider;
use App\Models\Debate;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('renders the debate index with models and the user debates', function () {
    Debate::factory()->forUser($this->user)->create(['topic' => 'My topic']);

    $this->actingAs($this->user)
        ->get(route('debates.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('debate/Index')
            ->has('models')
            ->has('debates', 1)
            ->where('activeDebate', null));
});

it('shows a debate with its participants and rounds', function () {
    $debate = Debate::factory()->forUser($this->user)->create();
    $debate->participants()->create([
        'model' => 'claude-haiku-4-5-20251001',
        'display_name' => 'Claude Haiku 4.5',
        'position' => 0,
        'accent' => 'violet',
    ]);

    $this->actingAs($this->user)
        ->get(route('debates.show', $debate))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('debate/Index')
            ->where('activeDebate.id', $debate->id)
            ->has('activeDebate.participants', 1));
});

it('hides another users debate', function () {
    $other = User::factory()->create();
    $debate = Debate::factory()->forUser($other)->create();

    $this->actingAs($this->user)
        ->get(route('debates.show', $debate))
        ->assertNotFound();
});

it('does not list debates from outside the account context', function () {
    $other = User::factory()->create();
    Debate::factory()->forUser($other)->create();

    $this->actingAs($this->user)
        ->get(route('debates.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('debates', 0));
});

it('lets the owner delete a debate', function () {
    $debate = Debate::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->delete(route('debates.destroy', $debate))
        ->assertRedirect(route('debates.index'));

    expect(Debate::find($debate->id))->toBeNull();
});
