<?php

use App\Models\App;
use App\Models\User;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('fails the workflow run when http.request targets an internal IP', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();

    $workflow = [
        'id' => 'wkf_'.strtolower((string) Str::ulid()),
        'slug' => 'ssrf_probe',
        'name' => 'SSRF probe',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => 'stp_'.strtolower((string) Str::ulid()),
            'type' => 'http.request',
            'method' => 'GET',
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]],
    ];

    // Literal internal IP → the guard blocks before any network call; the run
    // must end 'failed' with a clear error (no 500), per the StepFailedException
    // pattern.
    $run = app(WorkflowEngine::class)->run($app, [], $workflow, 'manual', [], $user);

    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('blocked');
});
