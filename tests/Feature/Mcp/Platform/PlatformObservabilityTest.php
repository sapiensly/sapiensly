<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Platform\ListFailedJobsTool;
use App\Mcp\Tools\Platform\PlatformHealthTool;
use App\Mcp\Tools\Platform\PlatformOverviewTool;
use App\Mcp\Tools\Platform\PlatformStackTool;
use App\Mcp\Tools\Platform\ReadPlatformAuditTool;
use App\Mcp\Tools\Platform\ReadPlatformLogsTool;
use App\Mcp\Tools\Platform\RetryFailedJobTool;
use App\Mcp\Tools\Platform\VerifyTenantIsolationTool;
use App\Models\PlatformAuditLog;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformInventory;
use App\Services\Platform\TenantIsolationVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The read-only half of the suite: what the platform looks like right now, and
 * the forensics for when it looks wrong.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg();
    $this->sysadmin = mcpSysadmin($this->org);
    mcpActingContext(['platform:admin']);
});

it('counts the whole platform, not just the bound organization', function () {
    $other = mcpOrg('Other');
    mcpMember($other);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformOverviewTool::class, ['days' => 7])
        ->assertOk()
        ->assertSee('organizations')
        ->assertSee('range_days');

    // Both organizations exist, so the count must reflect both.
    $counts = app(PlatformInventory::class)->counts();
    expect($counts['organizations'])->toBeGreaterThanOrEqual(2);
});

it('reports service health with warnings instead of failing when something is down', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformHealthTool::class, [])
        ->assertOk()
        ->assertSee('horizon')
        ->assertSee('queues')
        ->assertSee('warnings');
});

it('reads dependency versions off the lock files', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformStackTool::class, [])
        ->assertOk()
        ->assertSee('laravel/ai')
        ->assertSee('postgresql');
});

it('lists failed jobs and returns one in full by uuid', function () {
    $uuid = (string) Str::uuid();

    DB::connection('platform')->table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'ai',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\StreamChatTurn']),
        'exception' => 'RuntimeException: the model refused'.str_repeat(' padding', 200),
        'failed_at' => now(),
    ]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListFailedJobsTool::class, ['queue' => 'ai'])
        ->assertOk()
        ->assertSee('StreamChatTurn')
        ->assertSee($uuid);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListFailedJobsTool::class, ['uuid' => $uuid])
        ->assertOk()
        ->assertSee('the model refused');
});

it('refuses to act on failed jobs without naming a target', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(RetryFailedJobTool::class, ['action' => 'retry'])
        ->assertHasErrors();
});

it('discards a failed job and records it in the audit log', function () {
    $uuid = (string) Str::uuid();

    DB::connection('platform')->table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'imports',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RunImport']),
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(RetryFailedJobTool::class, ['action' => 'discard', 'uuid' => $uuid])
        ->assertOk();

    expect(DB::connection('platform')->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

    $entry = PlatformAuditLog::query()->where('action', 'retry_failed_job')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->actor_user_id)->toBe($this->sysadmin->id)
        ->and($entry->summary)->toContain('discard');
});

it('reads and filters the application log', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ReadPlatformLogsTool::class, ['limit' => 5, 'level' => 'error'])
        ->assertOk();
});

it('cannot be pointed outside the log directory', function () {
    // basename() is the boundary; a traversal attempt resolves to a name that
    // does not exist rather than reading the file.
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ReadPlatformLogsTool::class, ['file' => '../../.env'])
        ->assertHasErrors();
});

it('surfaces the audit trail, filtered', function () {
    app(PlatformAudit::class)->record(
        action: 'set_access_policy',
        actor: $this->sysadmin,
        summary: 'Updated access policy: two_factor_required',
        meta: ['api_key' => 'sk-should-not-survive'],
    );

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ReadPlatformAuditTool::class, ['action' => 'access_policy'])
        ->assertOk()
        ->assertSee('two_factor_required')
        ->assertSee('[redacted]');

    expect(PlatformAuditLog::first()->meta['api_key'])->toBe('[redacted]');
});

it('verifies that tenant isolation is actually enforced in Postgres', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(VerifyTenantIsolationTool::class, [])
        ->assertOk()
        ->assertSee('checked_tables');

    $result = app(TenantIsolationVerifier::class)->verify();

    expect($result['supported'])->toBeTrue()
        ->and($result['checked'])->toBeGreaterThan(0)
        // Every declared tenant table must really have RLS + its policy.
        ->and($result['issues'])->toBe([]);
});
