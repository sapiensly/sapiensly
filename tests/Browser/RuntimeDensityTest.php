<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

function densApp(string $density): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);
    $obj = 'obj_'.strtolower((string) Str::ulid());
    $a = 'fld_'.strtolower((string) Str::ulid());
    $b = 'fld_'.strtolower((string) Str::ulid());
    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'slug' => 'd_'.Str::lower(Str::random(5))]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0', 'id' => $app->id, 'slug' => $app->slug, 'name' => 'D', 'version' => 1,
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN', 'density' => $density, 'accent' => '#0059ff'],
        'objects' => [['id' => $obj, 'slug' => 'items', 'name' => 'Items', 'fields' => [
            ['id' => $a, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ['id' => $b, 'slug' => 'importe', 'name' => 'Importe', 'type' => 'currency', 'currency_code' => 'MXN'],
        ]]],
        'pages' => [['id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => 'items', 'path' => '/items', 'name' => 'Items', 'blocks' => [
            ['id' => 'blk_kpi000001', 'type' => 'metric_grid', 'columns' => 3, 'items' => [
                ['id' => 'itm_a00000011', 'label' => 'Items', 'icon' => 'users', 'query' => ['object_id' => $obj], 'aggregation' => 'count'],
                ['id' => 'itm_a00000012', 'label' => 'Total Importe', 'icon' => 'trending-up', 'query' => ['object_id' => $obj], 'aggregation' => 'sum', 'field_id' => $b, 'format' => 'currency'],
                ['id' => 'itm_a00000013', 'label' => 'Promedio', 'icon' => 'clock', 'query' => ['object_id' => $obj], 'aggregation' => 'avg', 'field_id' => $b, 'format' => 'currency'],
            ]],
            ['id' => 'blk_tab000001', 'type' => 'table', 'data_source' => ['object_id' => $obj, 'limit' => 20], 'columns' => [
                ['id' => 'col_a00000011', 'field_id' => $a], ['id' => 'col_a00000012', 'field_id' => $b],
            ]],
        ]]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);
    foreach (range(1, 8) as $n) {
        Record::create(['app_id' => $app->id, 'object_definition_id' => $obj, 'data' => ['folio' => "IT-00$n", 'importe' => $n * 1500]]);
    }

    return $app;
}

/**
 * Both rhythms shipped in the token layer and nothing consumed them: every
 * table measured its rows in a fixed step regardless. A token nothing spends
 * is decoration.
 */
it('packs a compact app more tightly, by spending the token', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = densApp('compact');

    // Two claims in one: the surface picked the compact rhythm, and the cell
    // actually spends it. Either alone would pass while the feature did
    // nothing — the tokens shipped a release before anything consumed them.
    visit("/r/{$app->slug}/items")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(<<<'JS'
        (() => {
            const surface = document.querySelector('.sp-app-surface');
            const token = getComputedStyle(surface)
                .getPropertyValue('--sp-cell-py').trim();
            const cell = document.querySelector('tbody td');
            const padding = getComputedStyle(cell).paddingTop;
            return token === '5px' && padding === token;
        })()
        JS);
})->group('browser');

it('gives a KPI icon its own tinted tile', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = densApp('comfortable');

    // At 16px beside a label the glyph read as punctuation. The tile takes its
    // colour from the tenant's accent, so it is not a blue chosen once.
    visit("/r/{$app->slug}/items")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(<<<'JS'
        (() => {
            const tile = document.querySelector('[data-block-type="metric_grid"] span.grid');
            if (!tile) return false;
            const bg = getComputedStyle(tile).backgroundColor;
            return bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent';
        })()
        JS);
})->group('browser');
