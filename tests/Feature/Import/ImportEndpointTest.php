<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\RunSpreadsheetImportJob;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ImportRecordsTool;
use App\Models\App;
use App\Models\AppImport;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Record;
use App\Models\User;
use App\Services\Import\RemoteSheetFetcher;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * The builder's upload flow: analyse (shows the plan, writes nothing) then run.
 * Both take the file — the client never sends back a plan, so a tampered
 * payload cannot map a column onto a field the planner would not have offered.
 */
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
        'slug' => 'ventas',
        'name' => 'Ventas',
        'visibility' => 'organization',
    ]);

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'ventas',
        'name' => 'Ventas',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [
            ['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
        ]],
    ], $this->owner);
});

function csvUpload(string $contents, string $name = 'clientes.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'up').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

it('returns a plan without writing anything', function () {
    $response = $this->actingAs($this->owner)->post(
        "/apps/{$this->testApp->id}/builder/import/analyze",
        ['file' => csvUpload("Nombre;Precio\nAcme;1.200,50\n")],
    );

    $response->assertOk()
        ->assertJsonPath('plan.mode', 'create_object')
        ->assertJsonPath('plan.mappings.0.type', 'string')
        ->assertJsonPath('plan.mappings.1.type', 'currency')
        // The object name defaults to the file's name — what the user called it.
        ->assertJsonPath('plan.object.name', 'clientes');

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('hands the import to a job instead of doing it in the request', function () {
    Queue::fake();

    $response = $this->actingAs($this->owner)->post(
        "/apps/{$this->testApp->id}/builder/import/run",
        ['file' => csvUpload("Nombre;Precio\nAcme;1.200,50\nGlobex;900,00\n"), 'object_name' => 'Clientes'],
    );

    // 202: accepted, not done. Thousands of validated writes cannot finish
    // inside an HTTP request, and pretending otherwise is what timed out.
    $response->assertStatus(202)->assertJsonPath('import.status', 'queued');

    Queue::assertPushed(RunSpreadsheetImportJob::class);
    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(0);

    // The run is readable straight away, so a reload finds it.
    $this->actingAs($this->owner)
        ->getJson("/apps/{$this->testApp->id}/builder/import/".$response->json('import.id'))
        ->assertOk()
        ->assertJsonPath('import.status', 'queued');
});

it('runs the queued import for real, and reports it row by row', function () {
    $response = $this->actingAs($this->owner)->post(
        "/apps/{$this->testApp->id}/builder/import/run",
        ['file' => csvUpload("Nombre;Precio\nAcme;1.200,50\nGlobex;900,00\n"), 'object_name' => 'Clientes'],
    )->assertStatus(202);

    // The queue runs inline in tests, so by here the job has been through.
    $import = AppImport::find($response->json('import.id'));

    expect($import->status)->toBe('completed')
        ->and($import->created_count)->toBe(2)
        ->and($import->failed_count)->toBe(0)
        ->and($import->processed)->toBe(2)
        ->and(Record::where('app_id', $this->testApp->id)->count())->toBe(2);
});

it('records a failed import instead of leaving it queued forever', function () {
    // A target object that does not exist: the plan throws inside the job.
    $response = $this->actingAs($this->owner)->post(
        "/apps/{$this->testApp->id}/builder/import/run",
        ['file' => csvUpload("Nombre\nAcme\n"), 'object_slug' => 'fantasma'],
    )->assertStatus(202);

    $import = AppImport::find($response->json('import.id'));

    expect($import->status)->toBe('failed')
        ->and($import->error)->toContain('fantasma')
        ->and($import->finished_at)->not->toBeNull();
});

it('refuses a file that has no rows to import', function () {
    $this->actingAs($this->owner)->post(
        "/apps/{$this->testApp->id}/builder/import/analyze",
        ['file' => csvUpload("Nombre,Precio\n")],
    )->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'no readable rows'));
});

it('refuses a file type that is not a spreadsheet', function () {
    $path = tempnam(sys_get_temp_dir(), 'up').'.exe';
    file_put_contents($path, 'binary');

    $this->actingAs($this->owner)->post(
        "/apps/{$this->testApp->id}/builder/import/analyze",
        ['file' => new UploadedFile($path, 'payload.exe', 'application/octet-stream', null, true)],
    )->assertStatus(422);
});

it('keeps another organization out of the import endpoints', function () {
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)->post(
        "/apps/{$this->testApp->id}/builder/import/run",
        ['file' => csvUpload("a\n1\n")],
    )->assertStatus(403);

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('rewrites a Google Sheets link to its CSV export, keeping the chosen tab', function () {
    expect(RemoteSheetFetcher::normalizeGoogleSheets('https://docs.google.com/spreadsheets/d/ABC123/edit#gid=456'))
        ->toBe('https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=456')
        // No tab named: the first one.
        ->and(RemoteSheetFetcher::normalizeGoogleSheets('https://docs.google.com/spreadsheets/d/ABC123/edit'))
        ->toBe('https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=0')
        // Already an export link — the caller's own parameters are left alone.
        ->and(RemoteSheetFetcher::normalizeGoogleSheets('https://docs.google.com/spreadsheets/d/ABC/export?format=tsv'))
        ->toBe('https://docs.google.com/spreadsheets/d/ABC/export?format=tsv')
        // Anything else passes through.
        ->and(RemoteSheetFetcher::normalizeGoogleSheets('https://example.com/data.csv'))
        ->toBe('https://example.com/data.csv');
});

it('refuses a link that is not http(s) before any request is made', function () {
    expect(fn () => app(RemoteSheetFetcher::class)->fetch('file:///etc/passwd'))
        ->toThrow(RuntimeException::class, 'must start with http');
});

it('queues an MCP import too, and reports its progress by id', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(ImportRecordsTool::class, [
            'app_slug' => $this->testApp->slug,
            'content' => "Nombre;Precio\nAcme;1.200,50\nGlobex;900,00\n",
            'object_name' => 'Clientes',
        ])
        ->assertOk()
        // Queued, not done: an agent importing thousands of rows must not
        // block its own call on the write path, same as the browser.
        ->assertSee('queued');

    // The run it just created — the id the response handed the agent.
    $import = AppImport::where('app_id', $this->testApp->id)->firstOrFail();

    // The queue runs inline in tests, so polling by id already finds it done —
    // the same call an agent makes, just without the waiting.
    SapiensServer::actingAs($this->owner)
        ->tool(ImportRecordsTool::class, [
            'app_slug' => $this->testApp->slug,
            'import_id' => $import->id,
        ])
        ->assertOk()
        ->assertSee('finished');

    expect(Record::where('app_id', $this->testApp->id)->count())->toBe(2);
});
