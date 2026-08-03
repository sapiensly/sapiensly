<?php

use App\Enums\MembershipRole;
use App\Mcp\Tools\Platform\PurgeOrganizationTool;
use App\Mcp\Tools\SysadminTool;
use App\Models\App;
use App\Models\Organization;
use App\Models\Record;
use App\Models\User;
use App\Services\Platform\OrganizationPurge;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Honouring a deletion request.
 *
 * Suspending an organization was a soft delete and nothing else: every record,
 * document, conversation and file stayed exactly where it was, hidden behind
 * RLS. That is the right default and the wrong answer to "delete my data".
 *
 * The rails are what most of this asserts. A purge is irreversible, so the
 * interesting behaviour is everything that stops it happening by accident, and
 * everything it refuses to take with it.
 */
function purgeableOrg(): array
{
    $org = Organization::create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(6)),
    ]);

    $owner = User::factory()->create(['organization_id' => $org->id]);

    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'slug' => 'purge_'.Str::lower(Str::random(6)),
    ]);

    foreach (range(1, 3) as $n) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => 'obj_ordenes0001',
            'organization_id' => $org->id,
            'data' => ['folio' => "A-{$n}"],
        ]);
    }

    return [$org, $owner, $app];
}

it('asks the database which tables carry a tenant key, rather than keeping a list', function () {
    // The whole point. A hand-maintained constant is wrong the day somebody
    // adds a table and does not think of this file, and the failure is silent:
    // the purge reports success while the new table still holds the rows.
    [$org, , $app] = purgeableOrg();

    $report = app(OrganizationPurge::class)->preview($org);

    expect(array_keys($report['tables']))->toContain('tenant.records')
        ->and(array_keys($report['tables']))->toContain('platform.apps')
        ->and($report['tables']['tenant.records'])->toBe(3)
        ->and($report['rows'])->toBeGreaterThan(3)
        ->and($app->exists)->toBeTrue();
});

it('deletes nothing while previewing', function () {
    [$org] = purgeableOrg();

    app(OrganizationPurge::class)->preview($org);

    expect(Record::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(3);
});

it('destroys every row belonging to the tenant, across both schemas', function () {
    [$org, , $app] = purgeableOrg();
    $org->delete();

    $result = app(OrganizationPurge::class)->purge($org);

    expect($result['stuck'])->toBe([])
        ->and(DB::connection('pgsql')->table('tenant.records')->where('organization_id', $org->id)->count())->toBe(0)
        ->and(DB::connection('pgsql')->table('platform.apps')->where('id', $app->id)->count())->toBe(0)
        ->and(Organization::withTrashed()->find($org->id))->toBeNull();
});

it('leaves the people, because a person is not tenant property', function () {
    // Deleting the account of somebody who also belongs elsewhere would destroy
    // a third party's access along with the tenant.
    [$org, $owner] = purgeableOrg();
    $org->delete();

    app(OrganizationPurge::class)->purge($org);

    $owner->refresh();

    expect($owner->exists)->toBeTrue()
        // …but not stranded on a context that no longer exists.
        ->and($owner->organization_id)->toBeNull();
});

it('leaves the audit log, because it is the record that this happened', function () {
    [$org] = purgeableOrg();

    DB::connection('pgsql')->table('platform.platform_audit_log')->insert([
        'id' => (string) Str::ulid(),
        'organization_id' => $org->id,
        'action' => 'organization.suspend',
        'summary' => 'Suspended it',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $org->delete();
    $result = app(OrganizationPurge::class)->purge($org);

    expect($result['kept'])->toContain('platform_audit_log')
        ->and(DB::connection('pgsql')->table('platform.platform_audit_log')->where('organization_id', $org->id)->count())
        ->toBe(1);
});

it('takes nothing belonging to a different organization', function () {
    [$doomed] = purgeableOrg();
    [$bystander, $neighbour, $neighbourApp] = purgeableOrg();

    $doomed->delete();
    app(OrganizationPurge::class)->purge($doomed);

    expect(DB::connection('pgsql')->table('tenant.records')->where('organization_id', $bystander->id)->count())->toBe(3)
        ->and(Organization::find($bystander->id))->not->toBeNull()
        ->and(App::find($neighbourApp->id))->not->toBeNull()
        ->and($neighbour->fresh()->organization_id)->toBe($bystander->id);
});

it('can be run again after a partial run', function () {
    // Re-running is how somebody finishes a purge that died halfway, so it has
    // to be safe and it has to pick up what is left.
    [$org] = purgeableOrg();
    $org->delete();

    $purge = app(OrganizationPurge::class);
    $purge->purge($org);
    $second = $purge->purge($org);

    expect($second['rows'])->toBe(0)
        ->and($second['stuck'])->toBe([]);
});

it('refuses an organization that has not been suspended first', function () {
    // A reversible step before the irreversible one is the only thing that
    // makes a mis-aimed purge survivable.
    [$org] = purgeableOrg();

    $this->artisan('organizations:purge', [
        'organization' => $org->slug,
        '--confirm' => $org->slug,
    ])->assertFailed();

    expect(Record::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(3);
});

it('refuses without the slug typed back', function () {
    // A confirmation somebody can give without reading it is not one.
    [$org] = purgeableOrg();
    $org->delete();

    $this->artisan('organizations:purge', [
        'organization' => $org->slug,
        '--confirm' => 'yes',
    ])->assertFailed();

    expect(Organization::withTrashed()->find($org->id))->not->toBeNull();
});

it('reports without destroying when asked to', function () {
    [$org] = purgeableOrg();

    $this->artisan('organizations:purge', [
        'organization' => $org->slug,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(Record::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(3);
});

it('destroys it when both rails are satisfied', function () {
    [$org] = purgeableOrg();
    $org->delete();

    $this->artisan('organizations:purge', [
        'organization' => $org->slug,
        '--confirm' => $org->slug,
    ])->assertSuccessful();

    expect(Organization::withTrashed()->find($org->id))->toBeNull();
});

it('is closed to somebody who is not a sysadmin', function () {
    // The tool sits behind the same double key as the rest of the platform
    // suite; this is the one that would be catastrophic to get wrong.
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $member = mcpMember($org, MembershipRole::Member);

    expect(app(PurgeOrganizationTool::class))
        ->toBeInstanceOf(SysadminTool::class)
        ->and($member->isSysAdmin())->toBeFalse();
});

it('takes the stored files too, not just the rows that named them', function () {
    // The part that fails silently: delete the row and the blob is still on the
    // bucket, unreachable and undeleted, which is the opposite of what somebody
    // asking for deletion was told happened.
    Storage::fake('local');
    Storage::disk('local')->put('orgs/acme/contrato.pdf', 'bytes');

    [$org, $owner, $app] = purgeableOrg();

    DB::connection('pgsql')->table('tenant.app_files')->insert([
        'id' => 'apf_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'organization_id' => $org->id,
        'disk' => 'local',
        'storage_path' => 'orgs/acme/contrato.pdf',
        'original_name' => 'contrato.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(OrganizationPurge::class)->preview($org)['files'])->toBe(1);

    $org->delete();
    $result = app(OrganizationPurge::class)->purge($org);

    expect($result['files'])->toBe(1)
        ->and($result['files_failed'])->toBe(0)
        ->and(Storage::disk('local')->exists('orgs/acme/contrato.pdf'))->toBeFalse();
});

it('counts the files it could not delete rather than calling them gone', function () {
    // Bytes left on a bucket are exactly what the person who asked for deletion
    // needs to hear about, so a failure is reported and not folded into the
    // success number.
    Storage::fake('local');

    [$org, , $app] = purgeableOrg();

    DB::connection('pgsql')->table('tenant.app_files')->insert([
        'id' => 'apf_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'organization_id' => $org->id,
        'disk' => 'no-such-disk',
        'storage_path' => 'orgs/acme/perdido.pdf',
        'original_name' => 'perdido.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $org->delete();
    $result = app(OrganizationPurge::class)->purge($org);

    expect($result['files_failed'])->toBe(1)
        ->and($result['files'])->toBe(0);
});
