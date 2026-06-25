<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\CreateAppTool;
use App\Mcp\Tools\Build\ProposeChangeTool;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Support\Str;

/** Seed an app with one object so propose_change has something to patch. */
function seedTrackerApp(User $user, string $slug): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => $slug,
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($app), [
        'objects' => [['name' => 'Tasks', 'slug' => 'tasks', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]]],
    ]);
    $manifests->createVersion($app, $manifest, $user, 'seed');

    return $app->refresh();
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('propose_change tells the model exactly what failed, not just a count', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($app), [
        'objects' => [['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]]],
    ]);
    $manifests->createVersion($app, $manifest, $this->user, 'seed');

    // A workflow whose ai.complete step is missing its required user_prompt.
    $ops = [['op' => 'add', 'path' => '/workflows', 'value' => [[
        'id' => 'wfl_'.strtolower((string) Str::ulid()),
        'slug' => 'cmo_idea',
        'name' => 'CMO Idea',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'ai.complete', 'output_variable' => 'idea']],
    ]]]];

    // A rejected change comes back as the SAME structured result validate_manifest
    // returns — {applied:false, valid:false, errors:[{path, message, code, ...}]} —
    // not a flattened, truncated string. The error names the exact missing prop
    // (its branch matched on `type`) instead of a generic per-type catalog hint.
    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, [
            'app_slug' => 'content_engine',
            'ops' => $ops,
            'change_summary' => 'Add CMO Idea workflow',
        ])
        ->assertOk()
        ->assertSee('"applied"')
        ->assertSee('"valid"')
        ->assertSee('"code"')
        ->assertSee('user_prompt');
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

it('clamps an over-long app description into a valid first manifest version', function () {
    $longDescription = str_repeat('Recruiting pipeline with candidates and vacancies. ', 40); // ~2000 chars
    expect(mb_strlen($longDescription))->toBeGreaterThan(500);

    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'long_desc',
        'description' => $longDescription,
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);

    expect(mb_strlen($manifest['description']))->toBeLessThanOrEqual(500);

    // The chain that used to fail: a >500 char description must still produce a
    // schema-valid first version instead of throwing on createVersion.
    $version = $manifests->createVersion($app, $manifest, $this->user, 'seed');
    expect($version->version_number)->toBe(1);
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

it('propose_change returns the resolved paths a patch landed at', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'tracker',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($app), [
        'objects' => [['name' => 'Tasks', 'slug' => 'tasks', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]]],
    ]);
    $manifests->createVersion($app, $manifest, $this->user, 'seed');

    // Append a workflow with '/workflows/-' — the response must resolve it to the
    // concrete index (the first workflow → /workflows/0) so a follow-up patch can
    // target it without re-reading the manifest.
    $ops = [['op' => 'add', 'path' => '/workflows/-', 'value' => [
        'id' => 'wfl_'.strtolower((string) Str::ulid()),
        'slug' => 'nightly',
        'name' => 'Nightly',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'log', 'message' => 'hi']],
    ]]];

    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, [
            'app_slug' => 'tracker',
            'ops' => $ops,
            'change_summary' => 'Add nightly workflow',
        ])
        ->assertOk()
        ->assertSee('"applied"')
        ->assertSee('changed_paths')
        ->assertSee('/workflows/0')
        ->assertSee('/workflows/-');
});

it('create_app replays the same result for a repeated idempotency_key', function () {
    $args = ['name' => 'Idem', 'slug' => 'idem_app', 'idempotency_key' => 'k-123'];

    SapiensServer::actingAs($this->user)->tool(CreateAppTool::class, $args)->assertOk()->assertSee('idem_app');
    // Retry with the SAME key replays the result — no "slug already exists" error,
    // no duplicate app.
    SapiensServer::actingAs($this->user)->tool(CreateAppTool::class, $args)->assertOk()->assertSee('idem_app');

    expect(App::where('user_id', $this->user->id)->where('slug', 'idem_app')->count())->toBe(1);
});

it('propose_change replays a repeated idempotency_key without creating a new version', function () {
    $app = seedTrackerApp($this->user, 'idemp');
    $args = [
        'app_slug' => 'idemp',
        'ops' => [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed']],
        'change_summary' => 'rename',
        'idempotency_key' => 'p-1',
    ];

    SapiensServer::actingAs($this->user)->tool(ProposeChangeTool::class, $args)->assertOk();
    $versionsAfterFirst = AppVersion::where('app_id', $app->id)->max('version_number');

    SapiensServer::actingAs($this->user)->tool(ProposeChangeTool::class, $args)->assertOk();
    $versionsAfterRetry = AppVersion::where('app_id', $app->id)->max('version_number');

    expect($versionsAfterRetry)->toBe($versionsAfterFirst);
});

it('propose_change accepts an omitted change_summary', function () {
    seedTrackerApp($this->user, 'nosum');

    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, [
            'app_slug' => 'nosum',
            'ops' => [['op' => 'replace', 'path' => '/name', 'value' => 'X']],
        ])
        ->assertOk()
        ->assertSee('"applied"');
});
