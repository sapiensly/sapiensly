<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\GetManifestSchemaTool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('returns the top-level shape and the definition catalog with no args', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetManifestSchemaTool::class, [])
        ->assertOk()
        ->assertSee('root_required')
        ->assertSee('definitions')
        ->assertSee('field_relation');
});

it('returns a specific definition sub-schema', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetManifestSchemaTool::class, ['definition' => 'field_relation'])
        ->assertOk()
        ->assertSee('field_relation')
        ->assertSee('cardinality');
});

it('resolves a friendly definition alias', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetManifestSchemaTool::class, ['definition' => 'field:relation'])
        ->assertOk()
        ->assertSee('field_relation');
});

it('errors helpfully on an unknown definition', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetManifestSchemaTool::class, ['definition' => 'nonsense'])
        ->assertHasErrors();
});
