<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\PortalPublisher;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The public portal surface end to end: who gets in, what they can see, and
 * what they may write. Every assertion here is a door — a regression in any one
 * of them puts tenant data in front of the internet, so they are written as the
 * hostile visitor, never as the author.
 */
function portal_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'slug' => 'support_desk',
        'name' => 'Support Desk',
        'visibility' => 'organization',
    ]);

    $this->ids = [
        'objTicket' => portal_id('obj'),
        'objInternal' => portal_id('obj'),
        'fldSubject' => portal_id('fld'),
        'fldNotes' => portal_id('fld'),
        'fldMemo' => portal_id('fld'),
        'rolStaff' => portal_id('rol'),
        'rolVisitor' => portal_id('rol'),
        'pagPublic' => portal_id('pag'),
        'pagStaff' => portal_id('pag'),
        'pagUnpoliced' => portal_id('pag'),
        'blkPublic' => portal_id('blk'),
        'blkInternal' => portal_id('blk'),
    ];

    $this->manifest = portalManifest($this->testApp->id, $this->ids);
    app(AppManifestService::class)->createVersion($this->testApp, $this->manifest, $this->owner);

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['objTicket'],
        'data' => ['subject' => 'Printer jammed', 'notes' => 'internal only']]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['objInternal'],
        'data' => ['memo' => 'payroll figures']]);
});

/**
 * A portal-shaped manifest: one page granted to visitors, one granted only to
 * staff, one with no page policy at all (the deny-by-default case).
 *
 * @param  array<string, string>  $ids
 * @return array<string, mixed>
 */
function portalManifest(string $appId, array $ids, bool $enabled = true, bool $allowWrites = false): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'support_desk',
        'name' => 'Support Desk',
        'version' => 1,
        'objects' => [
            [
                'id' => $ids['objTicket'], 'slug' => 'tickets', 'name' => 'Ticket',
                'fields' => [
                    ['id' => $ids['fldSubject'], 'slug' => 'subject', 'name' => 'Subject', 'type' => 'string'],
                    ['id' => $ids['fldNotes'], 'slug' => 'notes', 'name' => 'Notes', 'type' => 'string'],
                ],
            ],
            [
                'id' => $ids['objInternal'], 'slug' => 'internal', 'name' => 'Internal',
                'fields' => [
                    ['id' => $ids['fldMemo'], 'slug' => 'memo', 'name' => 'Memo', 'type' => 'string'],
                ],
            ],
        ],
        'pages' => [
            [
                'id' => $ids['pagPublic'], 'slug' => 'status', 'name' => 'Status', 'path' => '/status',
                'blocks' => [[
                    'id' => $ids['blkPublic'], 'type' => 'table',
                    'data_source' => ['object_id' => $ids['objTicket']],
                    'columns' => [['id' => portal_id('col'), 'field_id' => $ids['fldSubject']]],
                ]],
            ],
            [
                'id' => $ids['pagStaff'], 'slug' => 'staff', 'name' => 'Staff', 'path' => '/staff',
                'blocks' => [[
                    'id' => $ids['blkInternal'], 'type' => 'table',
                    'data_source' => ['object_id' => $ids['objInternal']],
                    'columns' => [['id' => portal_id('col'), 'field_id' => $ids['fldMemo']]],
                ]],
            ],
            [
                'id' => $ids['pagUnpoliced'], 'slug' => 'notes', 'name' => 'Notes', 'path' => '/notes',
                'blocks' => [[
                    'id' => portal_id('blk'), 'type' => 'table',
                    'data_source' => ['object_id' => $ids['objInternal']],
                    'columns' => [['id' => portal_id('col'), 'field_id' => $ids['fldMemo']]],
                ]],
            ],
        ],
        'permissions' => [
            'public' => ['enabled' => $enabled, 'role_id' => $ids['rolVisitor'], 'allow_writes' => $allowWrites],
            'roles' => [
                ['id' => $ids['rolStaff'], 'slug' => 'staff', 'name' => 'Staff', 'is_default' => true],
                ['id' => $ids['rolVisitor'], 'slug' => 'visitor', 'name' => 'Visitor', 'is_default' => false],
            ],
            'object_policies' => [
                [
                    'object_id' => $ids['objTicket'], 'role_id' => $ids['rolVisitor'],
                    'actions' => $allowWrites ? ['read', 'create'] : ['read'],
                    'field_restrictions' => ['hidden' => [$ids['fldNotes']]],
                ],
                ['object_id' => $ids['objInternal'], 'role_id' => $ids['rolStaff'], 'actions' => ['read']],
            ],
            'page_policies' => [
                ['page_id' => $ids['pagPublic'], 'role_id' => $ids['rolVisitor'], 'can_view' => true],
                ['page_id' => $ids['pagStaff'], 'role_id' => $ids['rolStaff'], 'can_view' => true],
            ],
        ],
    ];
}

/**
 * Publish through the real publisher. The refresh matters: createVersion moves
 * `current_version_id` on the row, and an in-memory model from before it still
 * points at nothing.
 */
function publishPortal($test): string
{
    return app(PortalPublisher::class)->publish($test->testApp->refresh())['public_slug'];
}

it('404s an app that was never published, even with the portal open', function () {
    $this->get('/a/support-desk')->assertNotFound();
});

it('serves a published portal to a visitor with no account', function () {
    $slug = publishPortal($this);

    $this->get("/a/{$slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('runtime/Page')
            ->where('page.slug', 'status')
            ->where('mount', "/a/{$slug}")
            ->where('manifest.agent', null),
        );
});

it('shows a visitor only the pages granted to the visitor role', function () {
    $slug = publishPortal($this);

    // The staff page and the page with NO policy are both absent: deny by
    // default means silence in the manifest is not permission.
    $this->get("/a/{$slug}")
        ->assertInertia(fn ($page) => $page
            ->has('manifest.pages', 1)
            ->where('manifest.pages.0.slug', 'status'),
        );

    $this->get("/a/{$slug}/staff")->assertNotFound();
    $this->get("/a/{$slug}/notes")->assertNotFound();
});

it('strips fields the visitor role may not read', function () {
    $slug = publishPortal($this);

    deferredBlockData($this, "/a/{$slug}")
        ->assertJsonPath("props.blockData.{$this->ids['blkPublic']}.rows.0.data.subject", 'Printer jammed')
        ->assertJsonMissingPath("props.blockData.{$this->ids['blkPublic']}.rows.0.data.notes");
});

it('takes the portal offline the moment the manifest closes it, with no unpublish', function () {
    $slug = publishPortal($this);
    $this->get("/a/{$slug}")->assertOk();

    $closed = portalManifest($this->testApp->id, $this->ids, enabled: false);
    app(AppManifestService::class)->createVersion($this->testApp, $closed, $this->owner);

    $this->get("/a/{$slug}")->assertNotFound();
});

it('404s a landing on the portal route — landings publish at /l', function () {
    $slug = publishPortal($this);
    $this->testApp->forceFill(['kind' => 'landing'])->save();

    $this->get("/a/{$slug}")->assertNotFound();
});

it('refuses every write while allow_writes is false', function () {
    $slug = publishPortal($this);

    $this->postJson("/a/{$slug}/actions", [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $this->ids['objTicket'],
            'values' => ['subject' => 'From a stranger'],
        ]],
    ])->assertStatus(403)->assertJsonPath('errors.0.type', 'read_only');

    expect(Record::where('object_definition_id', $this->ids['objTicket'])->count())->toBe(1);
});

it('accepts a granted write once allow_writes is on', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        portalManifest($this->testApp->id, $this->ids, allowWrites: true),
        $this->owner,
    );
    $slug = publishPortal($this);

    $this->postJson("/a/{$slug}/actions", [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $this->ids['objTicket'],
            'values' => ['subject' => 'From a stranger'],
        ]],
    ])->assertOk()->assertJsonPath('ok', true);

    expect(Record::where('object_definition_id', $this->ids['objTicket'])->count())->toBe(2);
});

it('refuses a write to an object the visitor role was never granted', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        portalManifest($this->testApp->id, $this->ids, allowWrites: true),
        $this->owner,
    );
    $slug = publishPortal($this);

    $this->postJson("/a/{$slug}/actions", [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $this->ids['objInternal'],
            'values' => ['memo' => 'injected'],
        ]],
    ])->assertStatus(422)->assertJsonPath('errors.0.type', 'forbidden');

    expect(Record::where('object_definition_id', $this->ids['objInternal'])->count())->toBe(1);
});

it('refuses run_workflow from a public page whatever the manifest says', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        portalManifest($this->testApp->id, $this->ids, allowWrites: true),
        $this->owner,
    );
    $slug = publishPortal($this);

    $this->postJson("/a/{$slug}/actions", [
        'actions' => [['type' => 'run_workflow', 'workflow_id' => portal_id('wfl')]],
    ])->assertStatus(422)->assertJsonPath('errors.0.type', 'action_not_public');
});

it('swallows a honeypot submission without writing', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        portalManifest($this->testApp->id, $this->ids, allowWrites: true),
        $this->owner,
    );
    $slug = publishPortal($this);

    $this->postJson("/a/{$slug}/actions", [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $this->ids['objTicket'],
            'values' => ['subject' => 'spam'],
        ]],
        'website' => 'http://bot.example',
    ])->assertOk()->assertJsonPath('ok', true);

    expect(Record::where('object_definition_id', $this->ids['objTicket'])->count())->toBe(1);
});

it('refuses to publish an app whose manifest never opened a portal', function () {
    $closed = portalManifest($this->testApp->id, $this->ids, enabled: false);
    app(AppManifestService::class)->createVersion($this->testApp, $closed, $this->owner);

    expect(fn () => app(PortalPublisher::class)->publish($this->testApp->refresh()))
        ->toThrow(InvalidArgumentException::class, 'no public portal configured');
});

it('stops serving the portal after it is unpublished', function () {
    $slug = publishPortal($this);
    $this->get("/a/{$slug}")->assertOk();

    app(PortalPublisher::class)->unpublish($this->testApp);

    $this->get("/a/{$slug}")->assertNotFound();
});

it('refuses an upload while the portal is read-only', function () {
    $slug = publishPortal($this);
    $path = tempnam(sys_get_temp_dir(), 'up').'.pdf';
    file_put_contents($path, '%PDF-1.4 test');

    $this->post("/a/{$slug}/uploads", [
        'file' => new UploadedFile($path, 'orden.pdf', 'application/pdf', null, true),
    ], ['Accept' => 'application/json'])->assertStatus(403)->assertJsonPath('error', 'read_only');
});

it('refuses a file type a stranger has no business uploading', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        portalManifest($this->testApp->id, $this->ids, allowWrites: true),
        $this->owner,
    );
    $slug = publishPortal($this);

    $path = tempnam(sys_get_temp_dir(), 'up').'.php';
    file_put_contents($path, '<?php echo 1;');

    // "Whatever the browser labelled it" is not a type check, and a scriptable
    // file on tenant storage is the worst thing a portal could accept.
    // Accept: JSON — the browser's uploader is axios, so a rejection is a 422
    // it can read, not a redirect to a page that does not exist.
    $this->post("/a/{$slug}/uploads", [
        'file' => new UploadedFile($path, 'shell.php', 'text/plain', null, true),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});
