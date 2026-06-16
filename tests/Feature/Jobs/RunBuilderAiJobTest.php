<?php

use App\Jobs\RunBuilderAiJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

function job_manifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_crm',
        'name' => 'Mini CRM',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_'.strtolower((string) Str::ulid()),
            'slug' => 'clientes',
            'name' => 'Cliente',
            'fields' => [
                ['id' => 'fld_'.strtolower((string) Str::ulid()), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    app(AppManifestService::class)->createVersion($this->testApp, job_manifest($this->testApp->id), $this->user);
    $this->conversation = BuilderConversation::create([
        'organization_id' => $this->testApp->organization_id,
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
});

it('failed() banks a checkpointed patch instead of discarding the timed-out turn', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Outbound Calls']],
        'change_summary' => 'created the app',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('applied')
        ->and($fresh->applied_version_id)->not->toBeNull()
        ->and(app(AppManifestService::class)->getActiveManifest($this->testApp->fresh())['name'])->toBe('Outbound Calls');
});

it('failed() surfaces the real reason when banking the checkpoint fails', function () {
    // proposed_patch whose RESULT is invalid: applyCheckpoint throws while
    // banking it. The job must carry that real reason onto the message (this
    // is where a permission/role error reaching the platform schema would
    // show up) instead of a generic timeout note.
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => 'Listo, guardé los cambios.',
        'proposed_patch' => [['op' => 'replace', 'path' => '/schema_version', 'value' => 'not-a-version']],
        'change_summary' => 'broken patch',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('error')
        ->and($fresh->applied_version_id)->toBeNull()
        ->and($fresh->content)->toContain('could not be applied')
        ->and($fresh->content)->toContain('Manifest validation failed');
});

it('failed() marks the message error when there is no checkpointed work', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    expect($message->fresh()->status)->toBe('error');
});

it('failed() leaves an already-completed turn untouched', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'applied',
        'content' => 'done',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('late timeout'));

    expect($message->fresh()->status)->toBe('applied');
});
