<?php

namespace Database\Seeders;

use App\Enums\Visibility;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a "mini-crm" demo App for the first user found. The manifest defines a
 * single `clientes` object with name/email/estado/monto and one page with a
 * table block. ~20 fake records get inserted so the runtime has something to
 * render in Fase 3.
 */
class DemoAppSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();
        if (! $user) {
            $this->command?->warn('No user exists — create one before seeding the demo app.');

            return;
        }

        $manifestService = app(AppManifestService::class);

        // Idempotent: drop existing demo so the seeder can be re-run safely.
        App::query()
            ->where('user_id', $user->id)
            ->where('slug', 'mini_crm')
            ->each(fn (App $a) => $a->delete());

        $appModel = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => 'mini_crm',
            'name' => 'Mini CRM',
            'description' => 'Demo app for the runtime',
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
        ]);

        [$manifest, $objectId] = $this->buildManifest($appModel);

        $manifestService->createVersion($appModel, $manifest, $user, 'demo seed');

        // Records live in the RLS-protected `tenant` schema: bind the demo
        // owner's scope so the inserts satisfy the tenant_isolation policy.
        app(TenantContext::class)->set($appModel->organization_id, $user->id);

        $estados = ['activo', 'prospecto', 'inactivo'];
        for ($i = 1; $i <= 20; $i++) {
            Record::create([
                'organization_id' => $appModel->organization_id,
                'user_id' => $user->id,
                'app_id' => $appModel->id,
                'object_definition_id' => $objectId,
                'data' => [
                    'nombre' => 'Cliente '.Str::random(6),
                    'email' => 'cliente'.$i.'@example.com',
                    'estado' => $estados[array_rand($estados)],
                    'monto' => random_int(1000, 50000),
                ],
                'created_by_user_id' => $user->id,
            ]);
        }

        $this->command?->info("Demo app '{$appModel->slug}' seeded (id={$appModel->id}).");
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function buildManifest(App $app): array
    {
        $objectId = 'obj_'.strtolower((string) Str::ulid());
        $nombre = 'fld_'.strtolower((string) Str::ulid());
        $email = 'fld_'.strtolower((string) Str::ulid());
        $estado = 'fld_'.strtolower((string) Str::ulid());
        $monto = 'fld_'.strtolower((string) Str::ulid());
        $pageId = 'pag_'.strtolower((string) Str::ulid());
        $tableId = 'blk_'.strtolower((string) Str::ulid());
        $statTotal = 'blk_'.strtolower((string) Str::ulid());
        $statRevenue = 'blk_'.strtolower((string) Str::ulid());
        $rolAdmin = 'rol_'.strtolower((string) Str::ulid());
        $rolUser = 'rol_'.strtolower((string) Str::ulid());

        $manifest = [
            'schema_version' => '1.0.0',
            'id' => $app->id,
            'slug' => $app->slug,
            'name' => $app->name,
            'description' => $app->description,
            'version' => 1,
            'objects' => [[
                'id' => $objectId,
                'slug' => 'clientes',
                'name' => 'Cliente',
                'name_plural' => 'Clientes',
                'primary_display_field_id' => $nombre,
                'fields' => [
                    ['id' => $nombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string', 'required' => true, 'max_length' => 100],
                    ['id' => $email, 'slug' => 'email', 'name' => 'Email', 'type' => 'string', 'max_length' => 200],
                    ['id' => $estado, 'slug' => 'estado', 'name' => 'Estado', 'type' => 'single_select', 'options' => [
                        ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'activo', 'label' => 'Activo', 'color' => '#10B981'],
                        ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'prospecto', 'label' => 'Prospecto', 'color' => '#3B82F6'],
                        ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'inactivo', 'label' => 'Inactivo', 'color' => '#6B7280'],
                    ]],
                    ['id' => $monto, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'],
                ],
            ]],
            'pages' => [[
                'id' => $pageId,
                'slug' => 'clientes',
                'name' => 'Clientes',
                'path' => '/clientes',
                'blocks' => [
                    [
                        'id' => $statTotal,
                        'type' => 'stat',
                        'label' => 'Total clientes',
                        'query' => ['object_id' => $objectId],
                        'aggregation' => 'count',
                        'format' => 'number',
                    ],
                    [
                        'id' => $statRevenue,
                        'type' => 'stat',
                        'label' => 'Suma de montos',
                        'query' => ['object_id' => $objectId],
                        'aggregation' => 'sum',
                        'field_id' => $monto,
                        'format' => 'currency',
                    ],
                    [
                        'id' => $tableId,
                        'type' => 'table',
                        'data_source' => [
                            'object_id' => $objectId,
                            'sort' => [['field_id' => $nombre, 'direction' => 'asc']],
                        ],
                        'columns' => [
                            ['id' => 'col_'.strtolower((string) Str::ulid()), 'field_id' => $nombre],
                            ['id' => 'col_'.strtolower((string) Str::ulid()), 'field_id' => $email],
                            ['id' => 'col_'.strtolower((string) Str::ulid()), 'field_id' => $estado],
                            ['id' => 'col_'.strtolower((string) Str::ulid()), 'field_id' => $monto],
                        ],
                    ],
                ],
            ]],
            'permissions' => [
                'roles' => [
                    ['id' => $rolAdmin, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                    ['id' => $rolUser, 'slug' => 'user', 'name' => 'User', 'is_default' => true],
                ],
                'object_policies' => [
                    ['object_id' => $objectId, 'role_id' => $rolAdmin, 'actions' => ['create', 'read', 'update', 'delete']],
                    ['object_id' => $objectId, 'role_id' => $rolUser, 'actions' => ['read']],
                ],
                'page_policies' => [
                    ['page_id' => $pageId, 'role_id' => $rolAdmin, 'can_view' => true],
                    ['page_id' => $pageId, 'role_id' => $rolUser, 'can_view' => true],
                ],
            ],
            'settings' => [
                'default_locale' => 'es-MX',
                'default_timezone' => 'America/Mexico_City',
                'default_currency' => 'MXN',
            ],
        ];

        return [$manifest, $objectId];
    }
}
