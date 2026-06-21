<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\CreateAppTool;
use App\Models\App;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('create_app creates an app seeded with a valid version 1', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateAppTool::class, [
            'name' => 'Support Desk',
            'slug' => 'support_desk',
            'description' => 'Ticketing',
        ])
        ->assertOk()
        ->assertSee('support_desk')
        ->assertSee('version_number');

    $app = App::where('user_id', $this->user->id)->where('slug', 'support_desk')->first();
    expect($app)->not->toBeNull();
    expect($app->current_version_id)->not->toBeNull();
    expect($app->versions()->count())->toBe(1);
});

it('create_app rejects a duplicate slug in the same account', function () {
    App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'taken',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(CreateAppTool::class, ['name' => 'Dup', 'slug' => 'taken'])
        ->assertHasErrors();
});

it('create_app rejects an invalid slug', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateAppTool::class, ['name' => 'Bad', 'slug' => 'Not Valid!'])
        ->assertHasErrors();

    expect(App::where('name', 'Bad')->exists())->toBeFalse();
});
