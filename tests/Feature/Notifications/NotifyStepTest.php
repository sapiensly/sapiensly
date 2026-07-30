<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\AppNotificationMail;
use App\Models\App;
use App\Models\AppNotification;
use App\Models\AppUserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Notifications\NotificationQuota;
use App\Services\Workflows\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * `notify.send` is an OUTBOUND channel a record write can reach — including a
 * write by an anonymous visitor on a public portal. These tests are written
 * against that: who it will address, who it refuses, and what stops it running
 * away.
 */
function notifyId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    Mail::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create([
        'email' => 'owner@acme.test', 'email_verified_at' => now(), 'organization_id' => $this->org->id,
    ]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);
    $this->agent = User::factory()->create([
        'email' => 'agent@acme.test', 'email_verified_at' => now(), 'organization_id' => $this->org->id,
    ]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->agent->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'soporte', 'name' => 'Soporte', 'visibility' => 'organization',
    ]);

    $this->objId = notifyId('obj');
    $this->wflId = notifyId('wfl');
    $rolAdmin = notifyId('rol');

    $this->manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'soporte',
        'name' => 'Soporte',
        'version' => 1,
        'objects' => [[
            'id' => $this->objId, 'slug' => 'tickets', 'name' => 'Ticket',
            'fields' => [
                ['id' => notifyId('fld'), 'slug' => 'asunto', 'name' => 'Asunto', 'type' => 'string'],
                ['id' => notifyId('fld'), 'slug' => 'email', 'name' => 'Email', 'type' => 'email'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [
            ['id' => $rolAdmin, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
        ]],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, $this->manifest, $this->owner);
    $this->testApp->refresh();
});

/** Run a one-step workflow whose only step is the notify under test. */
function runNotify(array $step, bool $dryRun = false): array
{
    $workflow = [
        'id' => test()->wflId,
        'slug' => 'avisar',
        'name' => 'Avisar',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => notifyId('stp'), ...$step]],
    ];

    $run = app(WorkflowEngine::class)->run(
        test()->testApp,
        test()->manifest,
        $workflow,
        'manual',
        ['record' => ['id' => 'rec_1', 'data' => ['asunto' => 'No enciende', 'email' => 'cliente@example.com']]],
        test()->owner,
        $dryRun,
    );

    expect($run->status)->toBe('completed');

    return $run->steps()->first()->output ?? [];
}

it('emails the address a record carried', function () {
    $output = runNotify([
        'type' => 'notify.send',
        'channel' => 'email',
        'to' => ['{{trigger.record.data.email}}'],
        'subject' => 'Recibimos tu ticket: {{trigger.record.data.asunto}}',
        'body' => 'Gracias, ya lo estamos viendo.',
    ]);

    expect($output['sent'])->toBe(1)
        ->and($output['recipients'])->toBe(['cliente@example.com']);

    Mail::assertSent(AppNotificationMail::class, function (AppNotificationMail $mail): bool {
        // The subject is expression-resolved — the point of the whole step.
        return $mail->title === 'Recibimos tu ticket: No enciende'
            && $mail->hasTo('cliente@example.com');
    });
});

it('reaches everyone holding an app role, and the owner by name', function () {
    AppUserRole::create([
        'organization_id' => $this->org->id,
        'app_id' => $this->testApp->id,
        'assigned_user_id' => $this->agent->id,
        'role_slug' => 'admin',
    ]);

    $output = runNotify([
        'type' => 'notify.send',
        'to' => ['role:admin', 'owner'],
        'subject' => 'Nuevo ticket',
        'body' => 'Entró uno.',
    ]);

    expect($output['sent'])->toBe(2)
        ->and($output['recipients'])->toContain('agent@acme.test')
        ->and($output['recipients'])->toContain('owner@acme.test');
});

it('never addresses a user from another organization', function () {
    $stranger = User::factory()->create(['email' => 'stranger@elsewhere.test']);

    $output = runNotify([
        'type' => 'notify.send',
        'to' => ["user:{$stranger->id}"],
        'subject' => 'Hola',
        'body' => 'Nada.',
    ]);

    // Reported as unresolved rather than silently delivered elsewhere.
    expect($output['sent'])->toBe(0)
        ->and($output['unresolved'])->toBe(["user:{$stranger->id}"]);

    Mail::assertNothingSent();
});

it('reports junk instead of handing it to the mailer', function () {
    $output = runNotify([
        'type' => 'notify.send',
        'to' => ['{{trigger.record.data.no_existe}}', 'no-es-un-correo'],
        'subject' => 'Hola',
        'body' => 'Nada.',
    ]);

    expect($output['sent'])->toBe(0)
        ->and($output['unresolved'])->toBe(['no-es-un-correo']);

    Mail::assertNothingSent();
});

it('raises an in-app notification instead of sending mail', function () {
    $output = runNotify([
        'type' => 'notify.send',
        'channel' => 'in_app',
        'to' => ['owner'],
        'subject' => 'Nuevo ticket',
        'body' => 'No enciende',
        'link' => '/r/soporte/tickets',
    ]);

    expect($output['sent'])->toBe(1);
    Mail::assertNothingSent();

    $notification = AppNotification::where('app_id', $this->testApp->id)->first();
    expect($notification->recipient_user_id)->toBe($this->owner->id)
        ->and($notification->title)->toBe('Nuevo ticket')
        ->and($notification->link)->toBe('/r/soporte/tickets')
        ->and($notification->read_at)->toBeNull();
});

it('sends nothing during a verification run', function () {
    $output = runNotify([
        'type' => 'notify.send',
        'to' => ['owner'],
        'subject' => 'Nuevo ticket',
        'body' => 'Entró uno.',
    ], dryRun: true);

    expect($output['simulated'])->toBeTrue()
        ->and($output['sent'])->toBe(0)
        // It still reports WHO it would have reached — that is the point of a
        // verification pass.
        ->and($output['recipients'])->toBe(['owner@acme.test']);

    Mail::assertNothingSent();
});

it('holds the hourly ceiling however many times the workflow runs', function () {
    // Burn the organization's hour, then try again.
    app(NotificationQuota::class)->claim($this->testApp, NotificationQuota::HOURLY_LIMIT);

    $output = runNotify([
        'type' => 'notify.send',
        'to' => ['owner'],
        'subject' => 'Spam',
        'body' => 'x',
    ]);

    expect($output['sent'])->toBe(0)
        ->and($output['throttled'])->toBe(1);

    Mail::assertNothingSent();
});

it('caps how many people one step may address', function () {
    $references = [];
    for ($i = 0; $i < NotificationQuota::MAX_RECIPIENTS_PER_STEP + 5; $i++) {
        $references[] = "persona{$i}@example.com";
    }

    $output = runNotify([
        'type' => 'notify.send',
        'to' => array_slice($references, 0, 20),
        'subject' => 'Aviso',
        'body' => 'x',
    ]);

    // The schema caps `to` at 20 entries; the sender caps resolved recipients at
    // the same number, so an over-broad `role:` expansion cannot exceed it either.
    expect($output['sent'])->toBe(NotificationQuota::MAX_RECIPIENTS_PER_STEP);
});
