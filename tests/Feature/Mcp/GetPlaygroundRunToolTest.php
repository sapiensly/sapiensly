<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Playground\GetPlaygroundRunTool;
use App\Models\PlaygroundRun;
use App\Models\User;

/**
 * Playground run trace over MCP: the full stored record of one AI capability
 * test run — input, sanitized response, raw provider payload, usage and
 * lifecycle timing — fetched by the pgrun_... id the Playground UI shows.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * @param  array<string, mixed>  $attrs
 */
function playgroundRun(User $owner, array $attrs = []): PlaygroundRun
{
    $run = new PlaygroundRun;
    $run->forceFill(array_merge([
        'organization_id' => $owner->organization_id,
        'user_id' => $owner->id,
        'capability' => 'text',
        'driver' => 'anthropic',
        'model' => 'claude-fable-5',
        'status' => PlaygroundRun::STATUS_OK,
        'input' => ['prompt' => 'Say hello'],
        'output_text' => 'Hello from the model.',
        'response' => ['text' => 'Hello from the model.', 'served_by' => 'Anthropic'],
        'raw' => ['provider' => 'Anthropic', 'stop_reason' => 'end_turn'],
        'usage' => ['total_tokens' => 42, 'cost' => 0.001],
        'duration_ms' => 1234,
        'queued_at' => now()->subSeconds(3),
        'started_at' => now()->subSeconds(2),
        'finished_at' => now(),
    ], $attrs));
    $run->save();

    return $run;
}

it('returns the full trace of a run by id', function () {
    $run = playgroundRun($this->user);

    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundRunTool::class, ['run_id' => $run->id])
        ->assertOk()
        ->assertSee($run->id)
        ->assertSee('Say hello')                    // input
        ->assertSee('Hello from the model.')        // sanitized response
        ->assertSee('end_turn')                     // raw provider payload
        ->assertSee('"total_tokens":42')
        ->assertSee('"duration_ms":1234')
        ->assertSee('queue_wait_ms')
        ->assertSee('Anthropic');                   // served_by
});

it('surfaces the error of a failed run', function () {
    $run = playgroundRun($this->user, [
        'status' => PlaygroundRun::STATUS_ERROR,
        'output_text' => null,
        'response' => null,
        'raw' => null,
        'usage' => null,
        'error' => 'No model is configured for this capability.',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundRunTool::class, ['run_id' => $run->id])
        ->assertOk()
        ->assertSee('"status":"error"')
        ->assertSee('No model is configured');
});

it('rejects a run from another tenant', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $run = playgroundRun($other);

    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundRunTool::class, ['run_id' => $run->id])
        ->assertSee('is visible to you');
});

it('rejects an unknown run id', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetPlaygroundRunTool::class, ['run_id' => 'pgrun_does_not_exist'])
        ->assertSee('is visible to you');
});
