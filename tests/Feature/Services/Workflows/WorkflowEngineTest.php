<?php

use App\Models\App;
use App\Models\Record;
use App\Models\WorkflowRun;
use App\Services\Records\RecordWriteService;
use App\Services\Workflows\ScriptRunner;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/** The real QuickJS sandbox needs Node + the npm package; skip cleanly otherwise. */
function we_sandbox_available(): bool
{
    if (! is_file(base_path('node_modules/quickjs-emscripten/package.json'))) {
        return false;
    }

    try {
        return Process::run(['node', '--version'])->successful();
    } catch (Throwable) {
        return false;
    }
}

function we_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function we_manifest(string $appId, array $objects, array $workflows = []): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'wf_test',
        'name' => 'WF Test',
        'version' => 1,
        'objects' => $objects,
        'pages' => [],
        'workflows' => $workflows,
        'permissions' => ['roles' => [['id' => we_id('rol'), 'slug' => 'admin', 'name' => 'A']]],
    ];
}

beforeEach(function () {
    $this->engine = app(WorkflowEngine::class);
    $this->testApp = App::factory()->create();
    $this->nombre = ['id' => we_id('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'];
    $this->object = ['id' => we_id('obj'), 'slug' => 'cli', 'name' => 'Cli', 'fields' => [$this->nombre]];
});

it('runs a workflow with a single log step', function () {
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'w', 'name' => 'W',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => we_id('stp'), 'type' => 'log', 'message' => 'hello', 'level' => 'info']],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual');

    expect($run)->toBeInstanceOf(WorkflowRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->steps()->count())->toBe(1)
        ->and($run->steps()->first()->status)->toBe('completed');
});

it('creates a record via a record.create step', function () {
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'create_one', 'name' => 'Create',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => we_id('stp'),
            'type' => 'record.create',
            'object_id' => $this->object['id'],
            'values' => ['nombre' => '{{trigger.who}}'],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['who' => 'Ana']);

    expect($run->status)->toBe('completed');
    $rec = Record::query()->where('app_id', $this->testApp->id)->first();
    expect($rec->data['nombre'])->toBe('Ana');
});

it('chains step outputs: query → branch → update', function () {
    $query = we_id('stp');
    $branch = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'chain', 'name' => 'Chain',
        'trigger' => ['type' => 'manual'],
        'steps' => [
            [
                'id' => $query, 'type' => 'record.query',
                'object_id' => $this->object['id'],
            ],
            [
                'id' => $branch, 'type' => 'set_variable',
                'variable' => 'count',
                'value' => '{{steps.'.$query.'.output.count}}',
            ],
        ],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->object['id'],
        'data' => ['nombre' => 'X'],
    ]);
    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->object['id'],
        'data' => ['nombre' => 'Y'],
    ]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual');

    expect($run->status)->toBe('completed')
        ->and($run->refresh()->variables['count'])->toBe(2);
});

it('marks the run failed when a step throws', function () {
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'bad', 'name' => 'Bad',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => we_id('stp'),
            'type' => 'record.create',
            'object_id' => we_id('obj'), // not in manifest
            'values' => ['nombre' => 'x'],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual');

    expect($run->status)->toBe('failed')
        ->and($run->error)->not->toBeNull()
        ->and($run->steps()->first()->status)->toBe('failed');
});

it('skips a step whose skip_if resolves truthy', function () {
    $stepA = we_id('stp');
    $stepB = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'skip', 'name' => 'Skip',
        'trigger' => ['type' => 'manual'],
        'steps' => [
            ['id' => $stepA, 'type' => 'set_variable', 'variable' => 'flag', 'value' => 'true'],
            [
                'id' => $stepB, 'type' => 'log', 'message' => 'never',
                'skip_if' => '{{vars.flag}}',
            ],
        ],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual');

    $steps = $run->steps()->get();
    expect($steps->where('step_id', $stepA)->first()->status)->toBe('completed')
        ->and($steps->where('step_id', $stepB)->first()->status)->toBe('skipped');
});

it('fires a record.created workflow when a record is created via the writer', function () {
    $auditField = ['id' => we_id('fld'), 'slug' => 'note', 'name' => 'Note', 'type' => 'string'];
    $auditObject = ['id' => we_id('obj'), 'slug' => 'audit', 'name' => 'Audit', 'fields' => [$auditField]];

    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'on_cli_create', 'name' => 'Audit',
        'trigger' => ['type' => 'record.created', 'object_id' => $this->object['id']],
        'steps' => [[
            'id' => we_id('stp'),
            'type' => 'record.create',
            'object_id' => $auditObject['id'],
            'values' => ['note' => '{{trigger.record.data.nombre}}'],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object, $auditObject], [$workflow]);

    app(RecordWriteService::class)->create($this->testApp, $manifest, $this->object['id'], ['nombre' => 'Ana']);

    $auditRecords = Record::query()
        ->where('app_id', $this->testApp->id)
        ->where('object_definition_id', $auditObject['id'])
        ->get();

    expect($auditRecords)->toHaveCount(1)
        ->and($auditRecords->first()->data['note'])->toBe('Ana');
});

it('does NOT fire a record.created workflow scoped to a different object', function () {
    $other = ['id' => we_id('obj'), 'slug' => 'otra', 'name' => 'Otra', 'fields' => [$this->nombre]];
    $auditObject = ['id' => we_id('obj'), 'slug' => 'audit', 'name' => 'Audit', 'fields' => [
        ['id' => we_id('fld'), 'slug' => 'note', 'name' => 'Note', 'type' => 'string'],
    ]];

    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'on_other', 'name' => 'OnOther',
        'trigger' => ['type' => 'record.created', 'object_id' => $other['id']],
        'steps' => [[
            'id' => we_id('stp'),
            'type' => 'record.create',
            'object_id' => $auditObject['id'],
            'values' => ['note' => 'fired'],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object, $other, $auditObject], [$workflow]);

    app(RecordWriteService::class)->create($this->testApp, $manifest, $this->object['id'], ['nombre' => 'X']);

    expect(Record::query()->where('object_definition_id', $auditObject['id'])->count())->toBe(0);
});

it('executes an http.request step against a faked endpoint', function () {
    Http::fake([
        'https://api.example.test/echo' => Http::response(['ok' => true, 'echoed' => 'hello'], 200),
    ]);

    $httpStep = we_id('stp');
    $assertStep = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'http', 'name' => 'HTTP',
        'trigger' => ['type' => 'manual'],
        'steps' => [
            [
                'id' => $httpStep, 'type' => 'http.request',
                'method' => 'GET',
                'url' => 'https://api.example.test/echo',
                'headers' => ['X-Test' => '{{trigger.token}}'],
            ],
            [
                'id' => $assertStep, 'type' => 'set_variable',
                'variable' => 'status',
                'value' => '{{steps.'.$httpStep.'.output.status}}',
            ],
        ],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['token' => 't0k']);

    expect($run->status)->toBe('completed')
        ->and($run->refresh()->variables['status'])->toBe(200);

    Http::assertSent(fn ($req) => $req->hasHeader('X-Test', 't0k'));
});

it('matches a branch case and runs its nested steps', function () {
    $branch = we_id('stp');
    $inner = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'br', 'name' => 'Br',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => $branch, 'type' => 'branch',
            'cases' => [[
                'condition' => '{{trigger.kind}} == "vip"',
                'steps' => [[
                    'id' => $inner, 'type' => 'set_variable',
                    'variable' => 'tagged_vip', 'value' => 'true',
                ]],
            ]],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['kind' => 'vip']);

    expect($run->status)->toBe('completed')
        ->and($run->refresh()->variables['tagged_vip'])->toBe('true');
});

it('matches a branch case using comparison and boolean operators', function () {
    $branch = we_id('stp');
    $inner = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'br2', 'name' => 'Br2',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => $branch, 'type' => 'branch',
            'cases' => [[
                'condition' => '{{trigger.total}} > 1000 && {{trigger.estado}} != "cancelado"',
                'steps' => [[
                    'id' => $inner, 'type' => 'set_variable',
                    'variable' => 'big_order', 'value' => 'true',
                ]],
            ]],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['total' => 1500, 'estado' => 'activo']);

    expect($run->status)->toBe('completed')
        ->and($run->refresh()->variables['big_order'])->toBe('true');
});

it('runs a script.run step (faked sandbox), resolving its input map and storing output', function () {
    $fake = new class extends ScriptRunner
    {
        /** @var list<array{code: string, input: mixed, timeoutMs: int}> */
        public array $calls = [];

        public function run(string $code, mixed $input = null, int $timeoutMs = 2000): mixed
        {
            $this->calls[] = ['code' => $code, 'input' => $input, 'timeoutMs' => $timeoutMs];

            return ['received' => $input];
        }
    };
    $this->app->instance(ScriptRunner::class, $fake);
    $engine = app(WorkflowEngine::class);

    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'scr', 'name' => 'Scr',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => we_id('stp'), 'type' => 'script.run', 'output_variable' => 'out',
            'code' => 'return input.n * 2;',
            'input' => ['n' => '{{trigger.n}}'],
            'timeout_ms' => 1500,
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $engine->run($this->testApp, $manifest, $workflow, 'manual', ['n' => 21]);

    expect($run->status)->toBe('completed')
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['input'])->toBe(['n' => 21])
        ->and($fake->calls[0]['timeoutMs'])->toBe(1500)
        ->and($run->refresh()->variables['out'])->toBe(['received' => ['n' => 21]]);
});

it('executes a real script.run step end-to-end in the QuickJS sandbox', function () {
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'realscr', 'name' => 'Real',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => we_id('stp'), 'type' => 'script.run', 'output_variable' => 'sum',
            'code' => 'let s = 0; for (const x of input.nums) { s += x; } return s;',
            'input' => ['nums' => '{{trigger.nums}}'],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['nums' => [1, 2, 3, 4]]);

    expect($run->status)->toBe('completed')
        ->and($run->refresh()->variables['sum'])->toBe(10);
})->skip(fn () => ! we_sandbox_available(), 'Node + quickjs-emscripten not available');

it('foreach creates one record per element of an inline array', function () {
    $loop = we_id('stp');
    $create = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'fe', 'name' => 'FE',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => $loop, 'type' => 'foreach',
            'items' => '{{trigger.names}}',
            'item_variable' => 'nombre',
            'steps' => [[
                'id' => $create, 'type' => 'record.create',
                'object_id' => $this->object['id'],
                'values' => ['nombre' => '{{vars.nombre}}'],
            ]],
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['names' => ['Ana', 'Beto', 'Caro']]);

    expect($run->status)->toBe('completed');
    $names = Record::query()->where('app_id', $this->testApp->id)->get()
        ->pluck('data.nombre')->sort()->values()->all();
    expect($names)->toBe(['Ana', 'Beto', 'Caro']);
});

it('foreach over a real script.run result creates one record per computed item', function () {
    $calc = we_id('stp');
    $loop = we_id('stp');
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'primes', 'name' => 'Primes',
        'trigger' => ['type' => 'manual'],
        'steps' => [
            [
                'id' => $calc, 'type' => 'script.run', 'output_variable' => 'calc',
                'code' => 'const out=[]; for (let n=input.min; n<=input.max; n++){ if(n<2) continue; let p=true; for(let d=2; d*d<=n; d++){ if(n%d===0){p=false;break;} } if(p) out.push(n); } return {primes: out};',
                'input' => ['min' => '{{trigger.min}}', 'max' => '{{trigger.max}}'],
            ],
            [
                'id' => $loop, 'type' => 'foreach',
                'items' => '{{steps.'.$calc.'.output.primes}}',
                'item_variable' => 'primo',
                'steps' => [[
                    'id' => we_id('stp'), 'type' => 'record.create',
                    'object_id' => $this->object['id'],
                    'values' => ['nombre' => '{{vars.primo}}'],
                ]],
            ],
        ],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['min' => 10, 'max' => 20]);

    expect($run->status)->toBe('completed');
    $values = Record::query()->where('app_id', $this->testApp->id)->get()
        ->map(fn ($r) => (int) $r->data['nombre'])->sort()->values()->all();
    expect($values)->toBe([11, 13, 17, 19]);
})->skip(fn () => ! we_sandbox_available(), 'Node + quickjs-emscripten not available');

it('fails the run when a real script throws inside the sandbox', function () {
    $workflow = [
        'id' => we_id('wkf'), 'slug' => 'failscr', 'name' => 'Fail',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => we_id('stp'), 'type' => 'script.run',
            'code' => 'throw new Error("boom");',
        ]],
    ];
    $manifest = we_manifest($this->testApp->id, [$this->object], [$workflow]);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual');

    expect($run->status)->toBe('failed');
})->skip(fn () => ! we_sandbox_available(), 'Node + quickjs-emscripten not available');
