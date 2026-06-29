<?php

use App\Enums\IntegrationKind;
use App\Models\Integration;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates a database connection storing the DSN in auth_config', function () {
    $data = [
        'name' => 'Analytics DB',
        'kind' => 'database',
        'base_url' => 'pgsql://db.example.com:5432/analytics',
        'auth_type' => 'none',
        'auth_config' => [
            'driver' => 'pgsql',
            'host' => 'db.example.com',
            'port' => 5432,
            'database' => 'analytics',
            'username' => 'reader',
            'password' => 'secret',
        ],
        'visibility' => 'private',
    ];

    $this->actingAs($this->user)
        ->post('/system/integrations', $data)
        ->assertRedirect();

    $integration = Integration::where('name', 'Analytics DB')->firstOrFail();

    expect($integration->kind)->toBe(IntegrationKind::Database);
    expect($integration->is_mcp)->toBeFalse();
    expect($integration->isDatabase())->toBeTrue();
    expect($integration->auth_config['host'])->toBe('db.example.com');
    expect($integration->auth_config['database'])->toBe('analytics');
});

it('does not force the http URL scheme on a database connection', function () {
    // A DSN base_url (no http://) must pass validation for a database kind.
    $this->actingAs($this->user)
        ->post('/system/integrations', [
            'name' => 'Mysql DB',
            'kind' => 'database',
            'base_url' => 'mysql://localhost:3306/shop',
            'auth_type' => 'none',
            'auth_config' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'shop', 'username' => 'u'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});
