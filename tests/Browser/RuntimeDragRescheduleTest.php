<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Moving a thing by dragging it, and what happens when the server says no.
 *
 * These two blocks are what proves the write seam. A drop writes to the screen
 * FIRST and sends the request afterwards — dragging a card and watching it snap
 * back for half a second while a round trip happens is the difference between a
 * calendar and a form with a calendar drawn on it.
 *
 * Which makes the ROLLBACK the interesting half, and the reason this is where
 * the seam gets exercised rather than a quiet refactor: a card left showing a
 * date the server refused is a lie the reader will plan on. It is also exactly
 * the contract an offline queue will need.
 */
function dragApp(bool $readOnly = false): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $titulo = 'fld_'.strtolower((string) Str::ulid());
    $inicio = 'fld_'.strtolower((string) Str::ulid());
    $fin = 'fld_'.strtolower((string) Str::ulid());
    $rol = 'rol_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'drg_'.Str::lower(Str::random(5)),
    ]);

    $permissions = ['roles' => [['id' => $rol, 'slug' => 'admin', 'name' => 'Admin']]];

    if ($readOnly) {
        // A role that may look and not touch. The controls must not be drawn:
        // a handle that always refuses is worse than no handle.
        $permissions = [
            'roles' => [['id' => $rol, 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true]],
            'object_policies' => [[
                'object_id' => $obj,
                'role_id' => $rol,
                'actions' => ['read'],
            ]],
        ];
    }

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Agenda',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'tareas',
            'name' => 'Tareas',
            'fields' => [
                ['id' => $titulo, 'slug' => 'titulo', 'name' => 'Título', 'type' => 'string'],
                ['id' => $inicio, 'slug' => 'inicio', 'name' => 'Inicio', 'type' => 'date'],
                ['id' => $fin, 'slug' => 'fin', 'name' => 'Fin', 'type' => 'date'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'agenda',
            'path' => '/agenda',
            'name' => 'Agenda',
            'blocks' => [
                [
                    'id' => 'blk_cal00000001',
                    'type' => 'calendar',
                    'data_source' => ['object_id' => $obj],
                    'date_field_id' => $inicio,
                    'title_field_id' => $titulo,
                ],
                [
                    'id' => 'blk_gantt0000001',
                    'type' => 'gantt',
                    'data_source' => ['object_id' => $obj],
                    'start_field_id' => $inicio,
                    'end_field_id' => $fin,
                    'title_field_id' => $titulo,
                ],
            ],
        ]],
        'permissions' => $permissions,
    ], $user);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => $obj,
        'data' => [
            'titulo' => 'Revisión',
            'inicio' => now()->startOfMonth()->addDays(9)->toDateString(),
            'fin' => now()->startOfMonth()->addDays(11)->toDateString(),
        ],
    ]);

    return $app;
}

it('offers no handles to a role that may only read', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = dragApp(readOnly: true);

    // ?as_role= rather than a second account: the app's OWNER bypasses every
    // policy by design, so a role test that signs in as them proves nothing.
    visit("/r/{$app->slug}/agenda?as_role=lector")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Revisión')
        ->assertScript(
            'document.querySelectorAll("[data-sp-calendar-event][draggable=true]").length',
            0,
        )
        ->assertScript(
            'document.querySelectorAll("[data-sp-gantt-bar].cursor-grab").length',
            0,
        );
})->group('browser');

it('offers them to a role that may change the record', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = dragApp();

    visit("/r/{$app->slug}/agenda")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(
            'document.querySelectorAll("[data-sp-calendar-event][draggable=true]").length',
            1,
        )
        ->assertScript('document.querySelectorAll("[data-sp-gantt-bar]").length', 1)
        ->assertScript(
            'document.querySelectorAll("[data-sp-gantt-bar].cursor-grab").length',
            1,
        );
})->group('browser');

it('moves the card the moment it is dropped, before the server answers', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = dragApp();

    $page = visit("/r/{$app->slug}/agenda")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Which day it starts on, and a day it is definitely not on.
    $from = now()->startOfMonth()->addDays(9)->toDateString();
    $to = now()->startOfMonth()->addDays(20)->toDateString();

    $page->assertScript(
        "!!document.querySelector('[data-sp-calendar-day=\"{$from}\"] [data-sp-calendar-event]')",
        true,
    );

    $page->script(<<<JS
        (() => {
            const ev = document.querySelector('[data-sp-calendar-event]');
            const target = document.querySelector('[data-sp-calendar-day="{$to}"]');
            ev.dispatchEvent(new DragEvent('dragstart', { bubbles: true }));
            target.dispatchEvent(new DragEvent('drop', { bubbles: true }));
            return true;
        })()
    JS);

    // It landed where it was dropped, and stayed there once the write went
    // through.
    $page->assertScript(<<<JS
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (document.querySelector('[data-sp-calendar-day="{$to}"] [data-sp-calendar-event]')) {
                    return true;
                }
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertNoJavaScriptErrors();

    expect(Record::where('app_id', $app->id)->first()->data['inicio'])->toBe($to);
})->group('browser');

it('puts the card back when the server refuses the move', function () {
    // The half that matters. Without it a refused write leaves the reader
    // looking at a date nobody agreed to.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = dragApp();

    $page = visit("/r/{$app->slug}/agenda")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $from = now()->startOfMonth()->addDays(9)->toDateString();
    $to = now()->startOfMonth()->addDays(20)->toDateString();

    // Make every write fail, the way a dropped connection would.
    $page->script(<<<'JS'
        const open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url) {
            if (String(url).includes('/actions')) {
                arguments[1] = '/r/__nope__/actions';
            }
            return open.apply(this, arguments);
        };
        true
    JS);

    $page->script(<<<JS
        (() => {
            const ev = document.querySelector('[data-sp-calendar-event]');
            const target = document.querySelector('[data-sp-calendar-day="{$to}"]');
            ev.dispatchEvent(new DragEvent('dragstart', { bubbles: true }));
            target.dispatchEvent(new DragEvent('drop', { bubbles: true }));
            return true;
        })()
    JS);

    // Back where it came from.
    $page->assertScript(<<<JS
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (document.querySelector('[data-sp-calendar-day="{$from}"] [data-sp-calendar-event]')) {
                    return true;
                }
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    expect(Record::where('app_id', $app->id)->first()->data['inicio'])->toBe($from);
})->group('browser');

it('moves a gantt bar by whole days and keeps its length', function () {
    // A different gesture from the calendar's: pointer events along a track,
    // where a pixel has to become a number of days. A task that changed length
    // because it was moved would be a scheduling bug nobody would forgive.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = dragApp();

    $page = visit("/r/{$app->slug}/agenda")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Remember where the bar sat, drag it most of the way along the track, and
    // wait for the WRITE to land — evidenced by the reloaded rows putting the
    // bar somewhere else, not by a timer.
    $page->script(<<<'JS'
        (() => {
            const bar = document.querySelector('[data-sp-gantt-bar]');
            // The axis rescales to the bars it has, so with one task the bar
            // sits at 0% before and after. What moves is the DATE LABELS.
            window.__axisBefore = bar.closest('.rounded-sp-sm').innerText;
            const track = bar.parentElement.getBoundingClientRect();
            const at = (x, type) => bar.dispatchEvent(new PointerEvent(type, {
                bubbles: true, clientX: x, clientY: track.top + 5, pointerId: 1,
            }));
            at(track.left + track.width * 0.1, 'pointerdown');
            at(track.left + track.width * 0.9, 'pointermove');
            at(track.left + track.width * 0.9, 'pointerup');
            return true;
        })()
    JS);

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                const bar = document.querySelector('[data-sp-gantt-bar]');
                const card = bar && bar.closest('.rounded-sp-sm');
                if (card && card.innerText !== window.__axisBefore) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertNoJavaScriptErrors();

    $record = Record::where('app_id', $app->id)->first();
    $start = Carbon::parse($record->data['inicio']);
    $end = Carbon::parse($record->data['fin']);

    // It moved…
    expect($record->data['inicio'])->not->toBe(now()->startOfMonth()->addDays(9)->toDateString())
        // …and it is still two days long, exactly as it was.
        ->and($start->diffInDays($end))->toBe(2.0);
})->group('browser');
