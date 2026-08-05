<?php

use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * A rejected patch has to say WHERE it landed, not only what was wrong with it.
 *
 * Pages are addressed by index and reasoned about by name, and when those
 * disagree the validator answers in ids — "obj_01… is not this form's object" —
 * which tells the model the patch is wrong without telling it where it went.
 * Observed on a live build: five consecutive rejections aiming at
 * `ordenes_detail`, each landing on a different page, after which the model
 * gave up on the edit and reported it as done.
 */
function locId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function twoPageManifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'servicio',
        'name' => 'Servicio',
        'version' => 1,
        'objects' => [[
            'id' => locId('obj'),
            'slug' => 'ordenes',
            'name' => 'Órdenes',
            'fields' => [
                ['id' => locId('fld'), 'slug' => 'numero', 'name' => 'Número', 'type' => 'string'],
            ],
        ]],
        'pages' => [
            [
                'id' => locId('pag'), 'slug' => 'refacciones_detail', 'name' => 'Refacción', 'path' => '/refacciones_detail',
                'blocks' => [['id' => locId('blk'), 'type' => 'heading', 'content' => 'Refacción', 'level' => 1]],
            ],
            [
                'id' => locId('pag'), 'slug' => 'ordenes_detail', 'name' => 'Orden', 'path' => '/ordenes_detail',
                'blocks' => [['id' => locId('blk'), 'type' => 'heading', 'content' => 'Orden', 'level' => 1]],
            ],
        ],
        'permissions' => [
            'roles' => [['id' => locId('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        twoPageManifest($this->testApp->id),
        $this->user,
    );
    $this->propose = new ProposeChangeTool(
        $this->testApp->fresh(),
        app(AppManifestService::class),
        app(ManifestValidator::class),
    );
});

it('names the page a rejected op actually landed on', function () {
    // Aiming at "the orders detail page" and hitting index 0, which is not it.
    $result = json_decode($this->propose->handle(new ToolRequest([
        'ops' => [[
            'op' => 'add',
            'path' => '/pages/0/blocks/-',
            'value' => ['id' => locId('blk'), 'type' => 'heading'], // no content → rejected
        ]],
        'change_summary' => 'Add a heading to the orders page',
    ])), true);

    expect($result['ok'])->toBeFalse();

    $located = collect($result['errors'])->filter(fn (array $e): bool => isset($e['at']));

    expect($located)->not->toBeEmpty()
        ->and($located->first()['at'])->toContain('refacciones_detail')
        ->and($located->first()['at'])->toContain('/pages/0');
});

it('locates every error, not just the first few that also carry a schema hint', function () {
    // The hint is capped at 3; the location must not inherit that cap, or the
    // errors past it lose the one fact that explains a mis-indexed patch.
    $ops = [];
    foreach (range(0, 4) as $i) {
        $ops[] = [
            'op' => 'add',
            'path' => '/pages/0/blocks/-',
            'value' => ['id' => locId('blk'), 'type' => 'heading'],
        ];
    }

    $result = json_decode($this->propose->handle(new ToolRequest([
        'ops' => $ops,
        'change_summary' => 'Several bad headings',
    ])), true);

    expect($result['ok'])->toBeFalse();

    $errors = collect($result['errors'])->filter(
        fn (array $e): bool => str_starts_with((string) ($e['path'] ?? ''), '/pages/0'),
    );

    expect($errors)->not->toBeEmpty()
        ->and($errors->every(fn (array $e): bool => isset($e['at'])))->toBeTrue();
});

it('locates an object as readily as a page', function () {
    // Objects are indexed too, and a field patched into the wrong one fails the
    // same way — the model needs the same answer.
    $result = json_decode($this->propose->handle(new ToolRequest([
        'ops' => [[
            'op' => 'add',
            'path' => '/objects/0/fields/-',
            'value' => ['id' => locId('fld'), 'slug' => 'roto', 'name' => 'Roto', 'type' => 'no_such_type'],
        ]],
        'change_summary' => 'Add a field of an unknown type',
    ])), true);

    expect($result['ok'])->toBeFalse();

    $located = collect($result['errors'])->filter(fn (array $e): bool => isset($e['at']));

    expect($located)->not->toBeEmpty()
        ->and($located->first()['at'])->toContain('object `ordenes`');
});
