<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\AppNotificationMail;
use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalUser;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\PortalPublisher;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Portal identity — the thing that finally makes "each customer sees only their
 * own rows" expressible on a public surface. Written as the stranger trying to
 * read someone else's, not as the author.
 */
function pid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    Mail::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'pedidos_portal', 'name' => 'Mis pedidos', 'visibility' => 'organization',
    ]);

    $this->ids = [
        'obj' => pid('obj'), 'fldRef' => pid('fld'), 'fldOwner' => pid('fld'),
        'rolStaff' => pid('rol'), 'rolGuest' => pid('rol'), 'rolMember' => pid('rol'),
        'pagPublic' => pid('pag'), 'pagMine' => pid('pag'),
        'blkMine' => pid('blk'),
    ];

    $this->manifest = identityManifest($this->testApp->id, $this->ids);
    app(AppManifestService::class)->createVersion($this->testApp, $this->manifest, $this->owner);

    $this->slug = app(PortalPublisher::class)->publish($this->testApp->refresh())['public_slug'];
});

/**
 * A portal with two doors: a stranger gets the public page; a signed-in visitor
 * assumes `member` and sees a page scoped to their own id.
 *
 * @param  array<string, string>  $ids
 * @return array<string, mixed>
 */
function identityManifest(string $appId, array $ids, string $signup = 'open'): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'pedidos_portal',
        'name' => 'Mis pedidos',
        'version' => 1,
        'objects' => [[
            'id' => $ids['obj'], 'slug' => 'pedidos', 'name' => 'Pedido',
            'fields' => [
                ['id' => $ids['fldRef'], 'slug' => 'referencia', 'name' => 'Referencia', 'type' => 'string'],
                ['id' => $ids['fldOwner'], 'slug' => 'cliente_id', 'name' => 'Cliente', 'type' => 'string'],
            ],
        ]],
        'pages' => [
            ['id' => $ids['pagPublic'], 'slug' => 'inicio', 'name' => 'Inicio', 'path' => '/inicio', 'blocks' => []],
            [
                'id' => $ids['pagMine'], 'slug' => 'mis_pedidos', 'name' => 'Mis pedidos', 'path' => '/mis_pedidos',
                'blocks' => [[
                    'id' => $ids['blkMine'], 'type' => 'table',
                    'data_source' => ['object_id' => $ids['obj']],
                    'columns' => [['id' => pid('col'), 'field_id' => $ids['fldRef']]],
                ]],
            ],
        ],
        'permissions' => [
            'public' => array_filter([
                'enabled' => true,
                'role_id' => $ids['rolGuest'],
                // A signed-in role with no way to sign in is rejected at save
                // time, so it only belongs here when there IS sign-in.
                'member_role_id' => $signup === 'none' ? null : $ids['rolMember'],
                'signup' => $signup,
                'allow_writes' => false,
            ], fn ($v): bool => $v !== null),
            'roles' => [
                ['id' => $ids['rolStaff'], 'slug' => 'staff', 'name' => 'Staff', 'is_default' => true],
                ['id' => $ids['rolGuest'], 'slug' => 'guest', 'name' => 'Visitante', 'is_default' => false],
                ['id' => $ids['rolMember'], 'slug' => 'member', 'name' => 'Cliente', 'is_default' => false],
            ],
            'object_policies' => [[
                'object_id' => $ids['obj'], 'role_id' => $ids['rolMember'], 'actions' => ['read'],
                // The whole point: rows scoped to whoever is signed in.
                'row_filter' => [
                    'op' => 'eq', 'field_id' => $ids['fldOwner'],
                    'value_expression' => '{{current_user.id}}',
                ],
            ]],
            'page_policies' => [
                ['page_id' => $ids['pagPublic'], 'role_id' => $ids['rolGuest'], 'can_view' => true],
                ['page_id' => $ids['pagMine'], 'role_id' => $ids['rolMember'], 'can_view' => true],
            ],
        ],
    ];
}

/** Sign in by actually following a magic link, as a person would. */
function signInPortal($test, string $email): PortalUser
{
    $test->postJson("/a/{$test->slug}/auth/request", ['email' => $email])->assertOk();

    $token = null;
    Mail::assertSent(AppNotificationMail::class, function (AppNotificationMail $mail) use ($email, &$token): bool {
        if (! $mail->hasTo($email)) {
            return false;
        }
        preg_match('#/auth/([a-f0-9]{64})#', (string) $mail->link, $m);
        $token = $m[1] ?? null;

        return true;
    });

    expect($token)->not->toBeNull();
    $test->get("/a/{$test->slug}/auth/{$token}")->assertRedirect("/a/{$test->slug}");

    return PortalUser::where('app_id', $test->testApp->id)->where('email', $email)->firstOrFail();
}

it('shows a stranger only the anonymous role\'s pages', function () {
    $this->get("/a/{$this->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('manifest.pages', 1)
            ->where('manifest.pages.0.slug', 'inicio')
            ->where('portalAuth.enabled', true)
            ->where('portalAuth.user', null),
        );
});

it('gives a signed-in visitor the member role, and only their own rows', function () {
    $ana = signInPortal($this, 'ana@example.com');

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['obj'],
        'data' => ['referencia' => 'A-1', 'cliente_id' => $ana->id]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['obj'],
        'data' => ['referencia' => 'B-9', 'cliente_id' => 'pusr_deotrapersona']]);

    // Signing in swapped the role, so a page the stranger could not see appears.
    $this->get("/a/{$this->slug}/mis_pedidos")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('portalAuth.user.email', 'ana@example.com'));

    // …and the row_filter against {{current_user.id}} finally resolves.
    deferredBlockData($this, "/a/{$this->slug}/mis_pedidos")
        ->assertJsonCount(1, "props.blockData.{$this->ids['blkMine']}.rows")
        ->assertJsonPath("props.blockData.{$this->ids['blkMine']}.rows.0.data.referencia", 'A-1');
});

it('burns a magic link on first use', function () {
    $this->postJson("/a/{$this->slug}/auth/request", ['email' => 'ana@example.com'])->assertOk();

    $token = null;
    Mail::assertSent(AppNotificationMail::class, function (AppNotificationMail $mail) use (&$token): bool {
        preg_match('#/auth/([a-f0-9]{64})#', (string) $mail->link, $m);
        $token = $m[1] ?? null;

        return true;
    });

    $this->get("/a/{$this->slug}/auth/{$token}");
    // A replay of the same URL finds nothing — even from the same browser.
    $this->post("/a/{$this->slug}/auth/logout");
    $this->get("/a/{$this->slug}/auth/{$token}");

    $this->get("/a/{$this->slug}")
        ->assertInertia(fn ($page) => $page->where('portalAuth.user', null));
});

it('refuses an expired link', function () {
    $ana = PortalUser::create([
        'organization_id' => $this->org->id, 'app_id' => $this->testApp->id,
        'email' => 'ana@example.com', 'status' => 'active',
        'login_token_hash' => hash('sha256', $t = str_repeat('a', 64)),
        'login_token_expires_at' => now()->subMinute(),
    ]);

    $this->get("/a/{$this->slug}/auth/{$t}");
    $this->get("/a/{$this->slug}")
        ->assertInertia(fn ($page) => $page->where('portalAuth.user', null));

    expect($ana->refresh()->last_login_at)->toBeNull();
});

it('answers the same way for an address it will not mail', function () {
    // Invite-only: an uninvited address gets the identical confirmation an
    // invited one does. A different answer would be an account oracle.
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        identityManifest($this->testApp->id, $this->ids, signup: 'invite'),
        $this->owner,
    );

    $uninvited = $this->postJson("/a/{$this->slug}/auth/request", ['email' => 'nadie@example.com'])->assertOk();

    PortalUser::create([
        'organization_id' => $this->org->id, 'app_id' => $this->testApp->id,
        'email' => 'invitada@example.com', 'status' => 'invited',
    ]);
    $invited = $this->postJson("/a/{$this->slug}/auth/request", ['email' => 'invitada@example.com'])->assertOk();

    expect($uninvited->json())->toBe($invited->json());

    // Only the invited address was actually mailed.
    Mail::assertSent(AppNotificationMail::class, 1);
});

it('sends nothing at all when the portal has no sign-in', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        identityManifest($this->testApp->id, $this->ids, signup: 'none'),
        $this->owner,
    );

    // Still the same answer — the endpoint never maps a portal's configuration.
    $this->postJson("/a/{$this->slug}/auth/request", ['email' => 'ana@example.com'])->assertOk();
    Mail::assertNothingSent();
});

it('refuses a blocked person however good their link is', function () {
    $ana = signInPortal($this, 'ana@example.com');
    $ana->forceFill(['status' => 'blocked'])->save();

    // The session outlives the identity it names; it must resolve to nobody.
    $this->get("/a/{$this->slug}")
        ->assertInertia(fn ($page) => $page->where('portalAuth.user', null));
});

it('keeps a portal session out of every other portal', function () {
    signInPortal($this, 'ana@example.com');

    $other = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'otro_portal', 'name' => 'Otro', 'visibility' => 'organization',
    ]);
    $otherIds = [
        'obj' => pid('obj'), 'fldRef' => pid('fld'), 'fldOwner' => pid('fld'),
        'rolStaff' => pid('rol'), 'rolGuest' => pid('rol'), 'rolMember' => pid('rol'),
        'pagPublic' => pid('pag'), 'pagMine' => pid('pag'), 'blkMine' => pid('blk'),
    ];
    $otherManifest = identityManifest($other->id, $otherIds);
    $otherManifest['slug'] = 'otro_portal';
    $otherManifest['name'] = 'Otro';
    app(AppManifestService::class)->createVersion($other, $otherManifest, $this->owner);
    $otherSlug = app(PortalPublisher::class)->publish($other->refresh())['public_slug'];

    // Signed in over there is a stranger over here — even inside one org.
    $this->get("/a/{$otherSlug}")
        ->assertInertia(fn ($page) => $page->where('portalAuth.user', null));
});

it('lets an invited address in, and only an invited one', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        identityManifest($this->testApp->id, $this->ids, signup: 'invite'),
        $this->owner,
    );

    // Before the invite, invite mode mails nobody — which is exactly why the
    // directory has to exist: without it the mode is unusable.
    $this->postJson("/a/{$this->slug}/auth/request", ['email' => 'cliente@example.com'])->assertOk();
    Mail::assertNothingSent();

    $this->actingAs($this->owner)->postJson(
        "/apps/{$this->testApp->id}/builder/portal-users",
        ['action' => 'invite', 'email' => 'cliente@example.com', 'name' => 'Cliente'],
    )->assertOk()->assertJsonPath('portal_users.0.status', 'invited');

    signInPortal($this, 'cliente@example.com');

    $this->get("/a/{$this->slug}")
        ->assertInertia(fn ($page) => $page->where('portalAuth.user.email', 'cliente@example.com'));
});

it('blocks someone without losing the records their id scopes', function () {
    $ana = signInPortal($this, 'ana@example.com');
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['obj'],
        'data' => ['referencia' => 'A-1', 'cliente_id' => $ana->id]]);

    $this->actingAs($this->owner)->postJson(
        "/apps/{$this->testApp->id}/builder/portal-users",
        ['action' => 'block', 'email' => 'ana@example.com'],
    )->assertOk();

    // The identity survives, so the row still has an owner…
    expect(PortalUser::find($ana->id))->not->toBeNull()
        ->and(Record::where('app_id', $this->testApp->id)->count())->toBe(1);

    // …and her pending link died with the decision.
    expect(PortalUser::find($ana->id)->login_token_hash)->toBeNull();
});

it('keeps another organization out of the portal directory', function () {
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)->postJson(
        "/apps/{$this->testApp->id}/builder/portal-users",
        ['action' => 'invite', 'email' => 'colado@example.com'],
    )->assertStatus(403);

    expect(PortalUser::where('app_id', $this->testApp->id)->count())->toBe(0);
});
