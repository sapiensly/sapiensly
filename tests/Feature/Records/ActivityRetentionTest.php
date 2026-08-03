<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\Record;
use App\Models\RecordEvent;
use App\Models\User;
use App\Services\Records\ActivityRetention;
use App\Services\Records\RecordTrail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

// `mcpMember` grants a spatie role, which has to exist first.
beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class));

/**
 * How long a trail is kept, and what makes that affordable.
 *
 * Writing an activity log is cheap; keeping it for ever is what makes it the
 * most expensive table in the system by year three. Three decisions carry the
 * cost: a default of one month, no entry for a change that changed nothing
 * (pinned in RecordTrailTest), and a nightly prune that deletes in batches.
 */
function retentionApp(?int $appMonths = null, ?int $orgMonths = null): App
{
    // A real organisation, because retention has one: `User::factory()` leaves
    // organization_id null (personal mode), and the org-level setting would
    // have had nowhere to live.
    $org = mcpOrg('Ret '.Str::random(4));
    $owner = mcpMember($org);

    if ($orgMonths !== null) {
        Organization::query()->whereKey($org->id)
            ->update(['activity_retention_months' => $orgMonths]);
    }

    return App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'slug' => 'ret_'.strtolower(Str::random(6)),
        'activity_retention_months' => $appMonths,
    ]);
}

function agedEvent(App $app, string $when): RecordEvent
{
    $event = RecordEvent::create([
        'organization_id' => $app->organization_id,
        'app_id' => $app->id,
        'record_id' => 'rec_'.strtolower(Str::random(10)),
        'object_definition_id' => 'obj_x00000001',
        'kind' => 'created',
    ]);

    // Written directly: `created_at` is what retention reads, and a factory
    // that cannot age a row cannot test a retention policy at all.
    RecordEvent::query()->whereKey($event->id)->update(['created_at' => new DateTimeImmutable($when)]);

    return $event->refresh();
}

it('is off until somebody turns it on', function () {
    // Off is the default for cost — almost nobody changes a default — but
    // mostly because a trail records who did what, and deciding to keep that
    // is a business's call about its own people. Not something a platform
    // starts doing to them because nobody said no.
    $app = retentionApp();

    expect(app(ActivityRetention::class)->monthsFor($app))->toBe(0)
        ->and(app(ActivityRetention::class)->isEnabled($app))->toBeFalse();
});

it('writes nothing at all while it is off', function () {
    // The saving is in not writing, not in writing and pruning later.
    $app = retentionApp();
    agedEvent($app, 'now'); // straight to the model, to prove the table works

    $record = Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_x00000001',
        'organization_id' => $app->organization_id,
        'data' => ['x' => 1],
    ]);

    app(RecordTrail::class)->created($app, ['objects' => []], $record, null);

    expect(RecordEvent::where('record_id', $record->id)->count())->toBe(0);
});

it('lets the organisation set it once for everything it runs', function () {
    expect(app(ActivityRetention::class)->monthsFor(retentionApp(orgMonths: 36)))->toBe(36);
});

it('lets one app keep longer than the rest', function () {
    // The one holding payroll keeps ten years while the others keep a month.
    expect(app(ActivityRetention::class)->monthsFor(retentionApp(appMonths: 120, orgMonths: 1)))->toBe(120);
});

it('reads a number nobody offers as the default, not as "for ever"', function () {
    // Set before a period was retired, or by hand. Falling back to the default
    // is recoverable; reading it as unlimited is a bill.
    expect(app(ActivityRetention::class)->monthsFor(retentionApp(appMonths: 47)))->toBe(0);
});

it('deletes what is past its keeping and nothing else', function () {
    $app = retentionApp(orgMonths: 6);

    $old = agedEvent($app, '-8 months');
    $recent = agedEvent($app, '-2 months');
    $today = agedEvent($app, 'now');

    $this->artisan('activity:prune')->assertSuccessful();

    expect(RecordEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(RecordEvent::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(RecordEvent::query()->whereKey($today->id)->exists())->toBeTrue();
});

it('leaves another app alone', function () {
    // The cutoff is per app, so a tenant keeping ten years is not pruned by a
    // neighbour keeping one month.
    $short = retentionApp(orgMonths: 1);
    $long = retentionApp(orgMonths: 120);

    $doomed = agedEvent($short, '-3 months');
    $spared = agedEvent($long, '-3 months');

    $this->artisan('activity:prune')->assertSuccessful();

    expect(RecordEvent::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(RecordEvent::query()->whereKey($spared->id)->exists())->toBeTrue();
});

it('reports without deleting when asked to', function () {
    $app = retentionApp(orgMonths: 1);
    $old = agedEvent($app, '-3 months');

    $this->artisan('activity:prune', ['--dry-run' => true])
        ->expectsOutputToContain('would delete')
        ->assertSuccessful();

    expect(RecordEvent::query()->whereKey($old->id)->exists())->toBeTrue();
});
