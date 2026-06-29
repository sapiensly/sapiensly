<?php

use App\Jobs\RunBuilderAiJob;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Record;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Convince TenantStorage that S3 is configured (it checks
 * config('filesystems.disks.s3.key/secret/bucket')) and fake the disk so we
 * never actually call AWS in tests.
 */
function fakeTenantS3(): void
{
    Config::set('filesystems.disks.s3.key', 'fake');
    Config::set('filesystems.disks.s3.secret', 'fake');
    Config::set('filesystems.disks.s3.bucket', 'fake');
    Storage::fake('s3');
}

/** Inverse: make sure no S3 config is present so TenantStorage refuses. */
function clearTenantS3(): void
{
    Config::set('filesystems.disks.s3.key', null);
    Config::set('filesystems.disks.s3.secret', null);
    Config::set('filesystems.disks.s3.bucket', null);
}

function bldcm(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => 'Mini',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
    app(AppManifestService::class)->createVersion($this->testApp, bldcm($this->testApp->id), $this->user);
});

it('redirects guests away from the builder page', function () {
    $this->get("/apps/{$this->testApp->id}/builder")->assertRedirect('/login');
});

it('renders the builder page and starts a conversation lazily', function () {
    $this->actingAs($this->user)
        ->get("/apps/{$this->testApp->id}/builder")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('apps/Builder')
            ->where('app.slug', $this->testApp->slug)
            ->has('manifest')
            ->has('conversation.id')
            ->has('conversation.messages')
            ->has('models')
            ->where('defaultModel', 'claude-haiku-4-5-20251001')
        );

    expect(BuilderConversation::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('reuses the active conversation across visits', function () {
    $this->actingAs($this->user)->get("/apps/{$this->testApp->id}/builder")->assertOk();
    $this->actingAs($this->user)->get("/apps/{$this->testApp->id}/builder")->assertOk();

    expect(BuilderConversation::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('blocks builder access to users who cannot see the App', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($other)
        ->get("/apps/{$this->testApp->id}/builder")
        ->assertForbidden();
});

it('approves a pending proposal and creates a new manifest version', function () {
    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'Renaming.',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed via Builder']],
        'change_summary' => 'rename',
        'status' => 'pending',
    ]);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/messages/{$msg->id}/approve")
        ->assertRedirect();

    $msg->refresh();
    expect($msg->status)->toBe('applied')
        ->and(AppVersion::query()->where('app_id', $this->testApp->id)->count())->toBe(2);

    $latest = AppVersion::query()->where('app_id', $this->testApp->id)->latest('version_number')->first();
    expect($latest->manifest['name'])->toBe('Renamed via Builder');
});

it('rejects a pending proposal without creating a version', function () {
    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'Bad idea.',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'X']],
        'change_summary' => 'nope',
        'status' => 'pending',
    ]);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/messages/{$msg->id}/reject")
        ->assertRedirect();

    expect($msg->refresh()->status)->toBe('rejected')
        ->and(AppVersion::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('rejects approve/reject of a message that does not belong to the App', function () {
    $otherApp = App::factory()->create(['user_id' => $this->user->id]);
    app(AppManifestService::class)->createVersion($otherApp, bldcm($otherApp->id), $this->user);
    $otherConv = BuilderConversation::create([
        'app_id' => $otherApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $msg = BuilderMessage::create([
        'conversation_id' => $otherConv->id,
        'role' => 'assistant',
        'content' => 'Cross-app proposal',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'X']],
        'status' => 'pending',
    ]);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/messages/{$msg->id}/approve")
        ->assertNotFound();
});

it('returns a schema payload with objects, record counts, workflows and system fields', function () {
    $objClientes = 'obj_'.strtolower((string) Str::ulid());
    $objOrders = 'obj_'.strtolower((string) Str::ulid());
    $fldNombre = 'fld_'.strtolower((string) Str::ulid());
    $fldRelacion = 'fld_'.strtolower((string) Str::ulid());
    $wfId = 'wkf_'.strtolower((string) Str::ulid());

    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [
        [
            'id' => $objClientes, 'slug' => 'clientes', 'name' => 'Cliente',
            'fields' => [
                ['id' => $fldNombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ],
        [
            'id' => $objOrders, 'slug' => 'orders', 'name' => 'Order',
            'fields' => [
                ['id' => 'fld_'.strtolower((string) Str::ulid()), 'slug' => 'numero', 'name' => 'Número', 'type' => 'string'],
                [
                    'id' => $fldRelacion,
                    'slug' => 'cliente',
                    'name' => 'Cliente',
                    'type' => 'relation',
                    'target_object_id' => $objClientes,
                    'cardinality' => 'many_to_one',
                ],
            ],
        ],
    ];
    $manifest['workflows'] = [[
        'id' => $wfId, 'slug' => 'audit_order', 'name' => 'Audit order create',
        'trigger' => ['type' => 'record.created', 'object_id' => $objOrders],
        'steps' => [['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'log', 'message' => 'x']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $objClientes,
        'data' => ['nombre' => 'Ana'],
    ]);
    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $objClientes,
        'data' => ['nombre' => 'Beto'],
    ]);

    $this->actingAs($this->user)
        ->get("/apps/{$this->testApp->id}/builder")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('apps/Builder')
            ->has('schema')
            ->has('schema.objects', 2)
            ->where("schema.record_counts.{$objClientes}", 2)
            // Orders has no records — its key may be absent rather than 0; both are acceptable.
            ->has("schema.workflows_by_object.{$objOrders}", 1)
            ->where("schema.workflows_by_object.{$objOrders}.0.id", $wfId)
            ->where("schema.workflows_by_object.{$objOrders}.0.trigger_type", 'record.created')
            // Each object should have system_fields injected (sys_created_at, sys_updated_at).
            ->has('schema.objects.0.system_fields', 2)
            ->where('schema.objects.0.system_fields.0.id', 'sys_created_at')
            ->where('schema.objects.0.system_fields.1.id', 'sys_updated_at')
        );
});

it('returns null schema when the app has no manifest yet', function () {
    $bareApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);

    $this->actingAs($this->user)
        ->get("/apps/{$bareApp->id}/builder")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('apps/Builder')
            ->where('schema', null)
        );
});

it('returns an empty-but-shaped schema when manifest has zero objects', function () {
    // The beforeEach manifest already has objects=[] — so this just hits the
    // "no objects" branch in buildSchema().
    $this->actingAs($this->user)
        ->get("/apps/{$this->testApp->id}/builder")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('schema.objects', [])
            ->where('schema.record_counts', [])
            ->where('schema.workflows_by_object', [])
        );
});

it('preview hides a block gated by a visibility expression until its param is set', function () {
    // The live runtime drops a block whose visibility.expression is falsy
    // (e.g. a cart shown only when {{params.order}} is set). The builder's
    // "Vista en vivo" must do the same, or it diverges from the deployed app.
    $pageId = 'pag_'.strtolower((string) Str::ulid());
    $alwaysId = 'blk_'.strtolower((string) Str::ulid());
    $gatedId = 'blk_'.strtolower((string) Str::ulid());

    $manifest = bldcm($this->testApp->id);
    $manifest['pages'] = [[
        'id' => $pageId, 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [
            ['id' => $alwaysId, 'type' => 'heading', 'content' => 'Siempre'],
            ['id' => $gatedId, 'type' => 'heading', 'content' => 'Carrito', 'visibility' => ['expression' => '{{params.order}}']],
        ],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    // No param → the gated block is filtered out, matching the runtime.
    $this->actingAs($this->user)
        ->get("/apps/{$this->testApp->id}/builder")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('preview.page.blocks', 1)
            ->where('preview.page.blocks.0.id', $alwaysId)
        );

    // With the param present → both blocks render, exactly like the live view.
    $this->actingAs($this->user)
        ->get("/apps/{$this->testApp->id}/builder?order=123")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('preview.page.blocks', 2)
        );
});

it('returns records for an object via objectRecords endpoint', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldId = 'fld_'.strtolower((string) Str::ulid());

    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [['id' => $fldId, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    foreach (['Ana', 'Beto', 'Carlos'] as $name) {
        Record::create([
            'app_id' => $this->testApp->id,
            'object_definition_id' => $objId,
            'data' => ['nombre' => $name],
        ]);
    }

    $response = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records")
        ->assertOk()
        ->json();

    expect($response['object']['id'])->toBe($objId)
        ->and($response['object']['slug'])->toBe('clientes')
        ->and($response['total'])->toBe(3)
        ->and($response['rows'])->toHaveCount(3)
        ->and($response['rows'][0])->toHaveKeys(['id', 'data', 'sys_created_at', 'sys_updated_at'])
        ->and(collect($response['rows'])->pluck('data.nombre')->sort()->values()->all())
        ->toBe(['Ana', 'Beto', 'Carlos']);
});

it('paginates objectRecords with limit + offset', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldId = 'fld_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'items', 'name' => 'Item',
        'fields' => [['id' => $fldId, 'slug' => 'n', 'name' => 'N', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    foreach (range(1, 5) as $i) {
        Record::create([
            'app_id' => $this->testApp->id,
            'object_definition_id' => $objId,
            'data' => ['n' => (string) $i],
        ]);
    }

    $response = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?limit=2&offset=2")
        ->assertOk()
        ->json();

    expect($response['total'])->toBe(5)
        ->and($response['rows'])->toHaveCount(2)
        ->and($response['limit'])->toBe(2)
        ->and($response['offset'])->toBe(2);
});

it('404s objectRecords when the object id is unknown', function () {
    $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/obj_does_not_exist/records")
        ->assertNotFound();
});

it('blocks objectRecords for users who cannot see the App', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'x', 'name' => 'X',
        'fields' => [['id' => 'fld_'.strtolower((string) Str::ulid()), 'slug' => 'a', 'name' => 'A', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $this->actingAs($other)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records")
        ->assertForbidden();
});

it('filters objectRecords with q (case-insensitive search across text fields)', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldNombre = 'fld_'.strtolower((string) Str::ulid());
    $fldEmail = 'fld_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [
            ['id' => $fldNombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ['id' => $fldEmail, 'slug' => 'email', 'name' => 'Email', 'type' => 'string'],
        ],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['nombre' => 'Ana', 'email' => 'ana@x.com']]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['nombre' => 'Beto', 'email' => 'beto@x.com']]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['nombre' => 'Carlos', 'email' => 'beta@x.com']]);

    // Case-insensitive match: "ANA" should find "Ana".
    $byName = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?q=ANA")
        ->assertOk()
        ->json();
    expect($byName['total'])->toBe(1)
        ->and($byName['rows'][0]['data']['nombre'])->toBe('Ana')
        ->and($byName['q'])->toBe('ANA');

    // Cross-field: "beta" hits Carlos' email AND Beto's nombre starts with the same chars — only Carlos in this case.
    $byEmail = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?q=beta")
        ->assertOk()
        ->json();
    expect($byEmail['total'])->toBe(1)
        ->and($byEmail['rows'][0]['data']['nombre'])->toBe('Carlos');

    // Partial substring: "et" appears in both Beto and beta@ → expect 2.
    $partial = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?q=et")
        ->assertOk()
        ->json();
    expect($partial['total'])->toBe(2);
});

it('sorts objectRecords ascending when sort_field_id + sort_dir are provided', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldNombre = 'fld_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [['id' => $fldNombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    foreach (['Carlos', 'Ana', 'Beto'] as $name) {
        Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['nombre' => $name]]);
    }

    $asc = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?sort_field_id={$fldNombre}&sort_dir=asc")
        ->assertOk()
        ->json();
    expect(collect($asc['rows'])->pluck('data.nombre')->all())->toBe(['Ana', 'Beto', 'Carlos'])
        ->and($asc['sort_field_id'])->toBe($fldNombre)
        ->and($asc['sort_dir'])->toBe('asc');

    $desc = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?sort_field_id={$fldNombre}&sort_dir=desc")
        ->assertOk()
        ->json();
    expect(collect($desc['rows'])->pluck('data.nombre')->all())->toBe(['Carlos', 'Beto', 'Ana']);
});

it('combines q + sort + offset coherently on objectRecords', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldNombre = 'fld_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'items', 'name' => 'Item',
        'fields' => [['id' => $fldNombre, 'slug' => 'n', 'name' => 'N', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    foreach (['alpha-1', 'alpha-2', 'alpha-3', 'beta-1'] as $n) {
        Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['n' => $n]]);
    }

    $r = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?q=alpha&sort_field_id={$fldNombre}&sort_dir=asc&limit=2&offset=1")
        ->assertOk()
        ->json();

    expect($r['total'])->toBe(3) // only the 3 alpha-* rows match q
        ->and($r['rows'])->toHaveCount(2)
        ->and(collect($r['rows'])->pluck('data.n')->all())->toBe(['alpha-2', 'alpha-3']);
});

it('returns zero rows (not all rows) when q is set but the object has no text fields', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldEdad = 'fld_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'people', 'name' => 'Person',
        'fields' => [['id' => $fldEdad, 'slug' => 'edad', 'name' => 'Edad', 'type' => 'number']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['edad' => 30]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['edad' => 40]]);

    $r = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?q=anything")
        ->assertOk()
        ->json();

    expect($r['total'])->toBe(0)
        ->and($r['rows'])->toBe([]);
});

it('persists chat image attachments to the tenant S3 disk', function () {
    Queue::fake();
    fakeTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $image = UploadedFile::fake()->image('screenshot.png', 100, 100);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/messages", [
            'conversation_id' => $conv->id,
            'message' => 'Take a look',
            'attachment' => $image,
        ])
        ->assertOk()
        ->assertJsonPath('streaming', true);

    $userMsg = BuilderMessage::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'user')
        ->first();

    expect($userMsg)->not->toBeNull()
        ->and($userMsg->attachment_path)->not->toBeNull()
        ->and($userMsg->attachment_mime)->toStartWith('image/')
        ->and($userMsg->attachment_disk)->toBe('s3');

    Storage::disk('s3')->assertExists($userMsg->attachment_path);
    // The legacy local path must NOT be used anymore.
    Storage::disk('local')->assertMissing($userMsg->attachment_path);

    Queue::assertPushed(RunBuilderAiJob::class, function (RunBuilderAiJob $job) use ($userMsg) {
        return $job->attachmentDisk === 's3'
            && $job->attachmentPath === $userMsg->attachment_path;
    });
});

it('forwards a chosen model to the builder job', function () {
    Queue::fake();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages", [
            'conversation_id' => $conv->id,
            'message' => 'crea un website bonito sobre cambio climático',
            'model' => 'claude-sonnet-4-5-20250929',
        ])
        ->assertOk();

    Queue::assertPushed(
        RunBuilderAiJob::class,
        fn (RunBuilderAiJob $job) => $job->modelOverride === 'claude-sonnet-4-5-20250929',
    );
});

it('rejects a model that is not an enabled chat model', function () {
    Queue::fake();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages", [
            'conversation_id' => $conv->id,
            'message' => 'hola',
            'model' => 'totally-made-up-model',
        ])
        ->assertStatus(422);

    Queue::assertNotPushed(RunBuilderAiJob::class);
});

it('returns 503 when sending a chat attachment with no S3 configured', function () {
    clearTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $image = UploadedFile::fake()->image('screenshot.png', 100, 100);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages", [
            'conversation_id' => $conv->id,
            'message' => 'Take a look',
            'attachment' => $image,
        ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'storage_not_configured');
});

it('rejects non-image attachments on sendMessage', function () {
    fakeTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $pdf = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages", [
            'conversation_id' => $conv->id,
            'message' => 'Take a look',
            'attachment' => $pdf,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment']);
});

it('serves attachment to the owner and forbids other users', function () {
    fakeTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Storage::disk('s3')->put('builder_chat_attachments/'.$this->testApp->id.'/sample.png', 'fake-png-bytes');

    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'user',
        'content' => 'Take a look',
        'status' => 'none',
        'attachment_path' => 'builder_chat_attachments/'.$this->testApp->id.'/sample.png',
        'attachment_mime' => 'image/png',
        'attachment_disk' => 's3',
    ]);

    $this->actingAs($this->user)
        ->get("/apps/builder/messages/{$msg->id}/attachment")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $other = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($other)
        ->get("/apps/builder/messages/{$msg->id}/attachment")
        ->assertForbidden();
});

it('visual-review dispatches the job with the Sonnet 4.5 model override', function () {
    Queue::fake();
    fakeTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $screenshot = UploadedFile::fake()->image('preview.jpg', 800, 600);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/visual-review", [
            'conversation_id' => $conv->id,
            'screenshot' => $screenshot,
            'page_slug' => 'inicio',
        ])
        ->assertOk();

    Queue::assertPushed(RunBuilderAiJob::class, function (RunBuilderAiJob $job) {
        // Sonnet 4.5 is what gets passed; the default Haiku constant would
        // be unset (null) on a normal sendMessage call instead.
        return $job->modelOverride === BuilderAiService::VISUAL_REVIEW_MODEL
            && str_starts_with($job->modelOverride, 'claude-sonnet-4-5');
    });
});

it('wireframe-import accepts an uploaded image and dispatches the job', function () {
    Queue::fake();
    fakeTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $image = UploadedFile::fake()->image('wireframe.png', 800, 600);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/wireframe-import", [
            'conversation_id' => $conv->id,
            'source' => 'image',
            'image' => $image,
            'business_context' => 'Vendo plantas a oficinas en CDMX.',
        ])
        ->assertOk()
        ->assertJsonPath('streaming', true);

    $userMsg = BuilderMessage::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'user')
        ->first();

    expect($userMsg)->not->toBeNull()
        ->and($userMsg->attachment_disk)->toBe('s3')
        ->and($userMsg->attachment_path)->toContain('builder_wireframes/'.$this->testApp->id.'/')
        ->and($userMsg->content)->toContain('Vendo plantas a oficinas en CDMX.')
        ->and($userMsg->content)->toContain('uploaded screenshot');

    Storage::disk('s3')->assertExists($userMsg->attachment_path);
    Queue::assertPushed(RunBuilderAiJob::class);
});

it('wireframe-import scrapes a URL and attaches the og:image', function () {
    Queue::fake();
    fakeTenantS3();

    // The wireframe page itself.
    Http::fake([
        'example.com/wireframe' => Http::response(<<<'HTML'
            <html><head>
                <title>Plant Care Dashboard</title>
                <meta property="og:title" content="Plant Care Dashboard" />
                <meta property="og:description" content="A dashboard to track watering and fertilizing." />
                <meta property="og:image" content="https://example.com/preview.png" />
            </head><body><main>
                <h1>Watering schedule</h1>
                <p>List of plants with last-watered date.</p>
            </main></body></html>
        HTML, 200, ['Content-Type' => 'text/html']),
        // The OG image follow-up. Tiny synthetic PNG-like blob — the importer
        // only checks Content-Type to accept the bytes; for the AI job the
        // actual content doesn't matter in test (Queue::fake() catches it).
        'example.com/preview.png' => Http::response(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/wireframe-import", [
            'conversation_id' => $conv->id,
            'source' => 'url',
            'url' => 'https://example.com/wireframe',
        ])
        ->assertOk()
        ->assertJsonPath('streaming', true);

    $userMsg = BuilderMessage::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'user')
        ->latest('created_at')
        ->first();

    expect($userMsg->content)->toContain('Plant Care Dashboard')
        ->and($userMsg->content)->toContain('Watering schedule')
        ->and($userMsg->attachment_path)->not->toBeNull()
        ->and($userMsg->attachment_disk)->toBe('s3');

    Storage::disk('s3')->assertExists($userMsg->attachment_path);
});

it('wireframe-import accepts pasted HTML without going to the network', function () {
    Queue::fake();
    // Note: NO fakeTenantS3 here — pure HTML has no image, so we never need
    // the bucket. The endpoint must still work end-to-end.

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $html = <<<'HTML'
        <html><head><title>CRM Lite</title></head>
        <body>
          <script>alert('noise');</script>
          <main>
            <h1 class="text-2xl font-bold">Leads</h1>
            <table class="w-full">
              <thead><tr><th>Name</th><th>Stage</th></tr></thead>
              <tbody><tr><td>Acme</td><td>Negotiation</td></tr></tbody>
            </table>
          </main>
        </body></html>
    HTML;

    $this->actingAs($this->user)
        ->post("/apps/{$this->testApp->id}/builder/wireframe-import", [
            'conversation_id' => $conv->id,
            'source' => 'html',
            'html' => $html,
        ])
        ->assertOk();

    $userMsg = BuilderMessage::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'user')
        ->latest('created_at')
        ->first();

    expect($userMsg->content)->toContain('CRM Lite')
        // Structural HTML excerpt must reach Claude — tags + classes both.
        ->and($userMsg->content)->toContain('```html')
        ->and($userMsg->content)->toContain('<table')
        ->and($userMsg->content)->toContain('text-2xl font-bold')
        // And the noise must be gone.
        ->and($userMsg->content)->not->toContain('<script')
        ->and($userMsg->content)->not->toContain("alert('noise')")
        ->and($userMsg->attachment_path)->toBeNull();
});

it('wireframe-import blocks SSRF attempts to loopback / private IPs', function () {
    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    foreach (['http://127.0.0.1/secret', 'http://10.0.0.1/admin', 'http://localhost/internal'] as $blocked) {
        $this->actingAs($this->user)
            ->postJson("/apps/{$this->testApp->id}/builder/wireframe-import", [
                'conversation_id' => $conv->id,
                'source' => 'url',
                'url' => $blocked,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'wireframe_url_failed');
    }
});

it('wireframe-import returns 503 when uploading an image without S3 configured', function () {
    clearTenantS3();

    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/wireframe-import", [
            'conversation_id' => $conv->id,
            'source' => 'image',
            'image' => UploadedFile::fake()->image('wf.png', 100, 100),
        ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'storage_not_configured');
});

it('falls back to sys_created_at desc when sort_field_id is invalid', function () {
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $manifest = bldcm($this->testApp->id);
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'x', 'name' => 'X',
        'fields' => [['id' => 'fld_'.strtolower((string) Str::ulid()), 'slug' => 'a', 'name' => 'A', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objId, 'data' => ['a' => 'one']]);

    $r = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/objects/{$objId}/records?sort_field_id=fld_doesnotexist&sort_dir=asc")
        ->assertOk()
        ->json();

    expect($r['sort_field_id'])->toBe('sys_created_at')
        ->and($r['sort_dir'])->toBe('asc'); // direction is preserved even when the field falls back
});

it('updates the accent colour via the design endpoint', function () {
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/design", ['accent' => '#FF8800'])
        ->assertOk()
        ->assertJsonPath('settings.accent', '#FF8800');

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    expect($manifest['settings']['accent'])->toBe('#FF8800');
});

it('accepts theme and font on the design endpoint', function () {
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/design", ['theme' => 'dark', 'font' => 'serif'])
        ->assertOk()
        ->assertJsonPath('settings.theme', 'dark')
        ->assertJsonPath('settings.font', 'serif');
});

it('rejects an invalid accent hex on the design endpoint', function () {
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/design", ['accent' => 'blue'])
        ->assertStatus(422);
});

it('rejects a design update with no fields', function () {
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/design", [])
        ->assertStatus(422);
});

it('blocks design updates from users who cannot see the App', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($other)
        ->postJson("/apps/{$this->testApp->id}/builder/design", ['accent' => '#FF8800'])
        ->assertForbidden();
});
