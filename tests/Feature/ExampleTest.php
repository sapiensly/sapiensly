<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('redirects guests to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('redirects authenticated users to the dashboard', function () {
    actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
