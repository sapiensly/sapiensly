<?php

use App\Services\Apps\Docs\AppDocs;
use App\Services\Apps\Docs\DocWords;

/**
 * The two documents every app carries, written from its manifest.
 *
 * These assert on the SENTENCES, not on the structure, because the sentence is
 * the product: a guide that says "press «Agregar Pago»" is only useful if that
 * is the button on the screen, and the only way that stays true is by reading
 * the label out of the same manifest the runtime renders.
 */
function docsManifest(array $overrides = []): array
{
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_test',
        'slug' => 'rentas',
        'name' => 'Rentas',
        'description' => 'Lleva contratos de renta y sus pagos.',
        'version' => 3,
        'settings' => [
            'default_locale' => 'es-MX',
            'default_currency' => 'MXN',
            'default_timezone' => 'America/Mexico_City',
        ],
        'objects' => [
            [
                'id' => 'obj_leases',
                'name' => 'Contratos',
                'slug' => 'leases',
                'primary_display_field_id' => 'fld_folio',
                'fields' => [
                    ['id' => 'fld_folio', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'required' => true],
                    ['id' => 'fld_rent', 'name' => 'Renta mensual', 'slug' => 'rent', 'type' => 'currency'],
                    ['id' => 'fld_status', 'name' => 'Estado', 'slug' => 'status', 'type' => 'single_select', 'options' => [
                        ['value' => 'activo', 'label' => 'Activo'],
                        ['value' => 'vencido', 'label' => 'Vencido'],
                    ]],
                    ['id' => 'fld_payments', 'name' => 'Pagos', 'slug' => 'payments', 'type' => 'relation', 'cardinality' => 'one_to_many', 'target_object_id' => 'obj_payments', 'inverse_field_id' => 'fld_lease'],
                    ['id' => 'fld_paid', 'name' => 'Total pagado', 'slug' => 'paid_total', 'type' => 'rollup', 'readonly' => true, 'aggregator' => 'sum', 'target_field_id' => 'fld_amount', 'via_relation_field_id' => 'fld_payments'],
                ],
            ],
            [
                'id' => 'obj_payments',
                'name' => 'Pagos',
                'slug' => 'payments',
                'primary_display_field_id' => 'fld_amount',
                'fields' => [
                    ['id' => 'fld_amount', 'name' => 'Monto', 'slug' => 'amount', 'type' => 'currency', 'required' => true],
                    ['id' => 'fld_lease', 'name' => 'contrato', 'slug' => 'contrato', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_leases', 'inverse_field_id' => 'fld_payments', 'on_delete' => 'set_null'],
                ],
            ],
        ],
        'pages' => [
            [
                'id' => 'pag_leases',
                'name' => 'Contratos',
                'slug' => 'leases',
                'path' => '/leases',
                'blocks' => [
                    [
                        'id' => 'blk_modal',
                        'type' => 'modal',
                        'title' => 'Agregar Contrato',
                        'blocks' => [[
                            'id' => 'blk_form',
                            'type' => 'form',
                            'mode' => 'create',
                            'object_id' => 'obj_leases',
                            'submit_label' => 'Guardar',
                            'fields' => [['field_id' => 'fld_folio'], ['field_id' => 'fld_rent'], ['field_id' => 'fld_status']],
                            'on_submit' => [
                                ['type' => 'create_record', 'object_id' => 'obj_leases', 'values' => ['folio' => '{{form.folio}}']],
                                ['type' => 'close_modal'],
                            ],
                        ]],
                    ],
                    [
                        'id' => 'blk_button',
                        'type' => 'button',
                        'label' => 'Agregar Contrato',
                        'on_click' => [['type' => 'open_modal', 'modal_block_id' => 'blk_modal']],
                    ],
                    [
                        'id' => 'blk_table',
                        'type' => 'table',
                        'object_id' => 'obj_leases',
                        'columns' => [
                            ['id' => 'col_folio', 'field_id' => 'fld_folio'],
                            ['id' => 'col_edit', 'type' => 'action', 'label' => 'Editar', 'on_click' => [['type' => 'open_modal', 'modal_block_id' => 'blk_modal']]],
                        ],
                    ],
                    [
                        'id' => 'blk_board',
                        'type' => 'kanban',
                        'editable' => true,
                        'data_source' => ['object_id' => 'obj_leases'],
                        'group_by_field_id' => 'fld_status',
                    ],
                ],
            ],
        ],
        'permissions' => [
            'roles' => [
                ['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => 'rol_user', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
            ],
            'object_policies' => [
                ['role_id' => 'rol_admin', 'object_id' => 'obj_leases', 'actions' => ['create', 'read', 'update', 'delete']],
                ['role_id' => 'rol_user', 'object_id' => 'obj_leases', 'actions' => ['read']],
            ],
        ],
    ];

    return array_replace_recursive($manifest, $overrides);
}

describe('the user guide', function () {
    it('walks somebody through adding a record using the labels on the screen', function () {
        $manual = (new AppDocs)->manual(docsManifest())->toMarkdown();

        // The page, the button and the submit label all come from the manifest.
        // Guessing any of them is how a guide sends people to a button that is
        // not there.
        expect($manual)->toContain('Abre «Contratos» en el menú.')
            ->and($manual)->toContain('Presiona «Agregar Contrato».')
            ->and($manual)->toContain('Termina con «Guardar».')
            ->and($manual)->toContain('Obligatorios: Folio.');
    });

    it('names the field a total adds up, not just the object', function () {
        // "The total of its Payments" is not a quantity anybody recognises.
        expect((new AppDocs)->manual(docsManifest())->toMarkdown())
            ->toContain('La suma de Monto de sus Pagos');
    });

    it('describes a select by its choices and a link by what it points at', function () {
        $manual = (new AppDocs)->manual(docsManifest())->toMarkdown();

        expect($manual)->toContain('Una de: Activo, Vencido')
            // Singular: a payment belongs to one contract, not to "Contratos".
            ->and($manual)->toContain('A qué Contrato pertenece');
    });

    it('mentions dragging cards only when the board actually allows it', function () {
        expect((new AppDocs)->manual(docsManifest())->toMarkdown())
            ->toContain('arrastrar las tarjetas');

        $fixed = docsManifest();
        $fixed['pages'][0]['blocks'][3]['editable'] = false;

        expect((new AppDocs)->manual($fixed)->toMarkdown())
            ->not->toContain('arrastrar las tarjetas');
    });

    it('never mentions an id, an object or a block', function () {
        // The guide's reader has no idea what any of those are. A single
        // `obj_…` in it is the tell that a technical sentence leaked across.
        $manual = (new AppDocs)->manual(docsManifest())->toMarkdown();

        expect($manual)->not->toContain('obj_')
            ->and($manual)->not->toContain('fld_')
            ->and($manual)->not->toContain('blk_')
            ->and($manual)->not->toContain('{{');
    });

    it('speaks the language the app was written in', function () {
        $english = docsManifest();
        $english['settings']['default_locale'] = 'en-US';

        $manual = (new AppDocs)->manual($english)->toMarkdown();

        expect($manual)->toContain('User guide')
            ->and($manual)->toContain('Open «Contratos» from the menu.')
            ->and($manual)->not->toContain('Presiona');
    });

    it('leaves out a section the app has nothing to say in', function () {
        // This app ships no workflows, so there is no "what happens on its own"
        // — an empty heading reads as a missing feature.
        expect((new AppDocs)->manual(docsManifest())->toMarkdown())
            ->not->toContain('Qué pasa solo');
    });
});

describe('the technical sheet', function () {
    it('resolves every id it prints beside a name', function () {
        $doc = (new AppDocs)->technical(docsManifest())->toMarkdown();

        // The relation table, read left to right, is the whole relationship.
        expect($doc)->toContain('| Pagos (payments) | contrato (contrato) | many_to_one | Contratos (leases) | set_null |')
            // A rollup's wiring, with the ids turned back into names.
            ->and($doc)->toContain('aggregator=sum · via_relation=Pagos · target=Monto');
    });

    it('groups an action sequence into the one thing pressing the button does', function () {
        $doc = (new AppDocs)->technical(docsManifest())->toMarkdown();

        expect($doc)->toContain('create_record leases 1 values → close_modal')
            // Named by its modal's title: a form has no label of its own, and
            // two of them on a page are otherwise indistinguishable.
            ->and($doc)->toContain('Contratos › Agregar Contrato');
    });

    it('shows the block tree including what is nested inside a modal', function () {
        $doc = (new AppDocs)->technical(docsManifest())->toMarkdown();

        expect($doc)->toContain('- modal — «Agregar Contrato»')
            ->and($doc)->toContain('  - form — leases');
    });

    it('hands over the pointers a patch is written against', function () {
        $doc = (new AppDocs)->technical(docsManifest())->toMarkdown();

        expect($doc)->toContain('**/objects/0**: Contratos')
            ->and($doc)->toContain('**/pages/0**: Contratos');
    });

    it('lays the permissions out as a matrix of object against role', function () {
        $doc = (new AppDocs)->technical(docsManifest())->toMarkdown();

        expect($doc)->toContain('| Objeto | Admin | User |')
            ->and($doc)->toContain('| leases | CRUD | R |');
    });
});

describe('an app with nothing in it', function () {
    it('produces a document rather than an error', function () {
        // A manifest with no objects reaches here — an app mid-build, or one
        // whose version failed. Both documents must still render.
        $docs = new AppDocs;

        // No settings, so no locale — English, the fallback.
        expect($docs->manual(['name' => 'Vacía'])->toMarkdown())->toContain('User guide')
            ->and($docs->technical([])->toMarkdown())->toContain('Technical sheet');
    });
});

describe('the words the documents are written in', function () {
    it('says every phrase in every language it claims to speak', function () {
        // A half-translated table is how an English heading ends up inside an
        // otherwise Spanish guide. Asked as "which keys are absent" rather
        // than "which values match English", because half the technical
        // headings are legitimately the same word in four languages.
        foreach (array_diff(DocWords::languages(), ['en']) as $lang) {
            expect(DocWords::missingKeys($lang))->toBe([], "{$lang} is incomplete");
        }
    });

    it('swaps the Spanish conjunction before an i- sound', function () {
        // Object names go straight into these lists, so "Contratos e
        // Incidencias" comes up the first time an app has an Incidencias.
        $words = DocWords::for('es-MX');

        expect($words->list(['Contratos', 'Incidencias']))->toBe('Contratos e Incidencias')
            ->and($words->list(['Contratos', 'Pagos']))->toBe('Contratos y Pagos');
    });

    it('falls back to a phrase, never to a key', function () {
        expect(DocWords::for('de-DE')->get('s_screens'))->toBe('The screens');
    });
});

/**
 * `columns` is two different things in the manifest schema: a list of column
 * definitions on table/related_list/data_grid, and a plain COUNT on the grid
 * blocks. Both writers walked every block and iterated it, so the first app
 * with a dashboard made read_app_docs fail with a TypeError instead of
 * returning a document — and nearly every app has a dashboard.
 */
describe('the overloaded columns key', function () {
    function gridManifest(): array
    {
        $manifest = docsManifest();
        $manifest['pages'][] = [
            'id' => 'pag_dash',
            'name' => 'Tablero',
            'slug' => 'dashboard',
            'path' => '/dashboard',
            'blocks' => [
                // The count form, on the two blocks a dashboard is built from.
                ['id' => 'blk_metrics', 'type' => 'metric_grid', 'columns' => 5, 'items' => [
                    ['id' => 'itm_1', 'label' => 'Contratos', 'query' => ['object_id' => 'obj_leases'], 'aggregation' => 'count'],
                ]],
                ['id' => 'blk_cards', 'type' => 'card_grid', 'columns' => 3, 'data_source' => ['object_id' => 'obj_leases']],
            ],
        ];

        return $manifest;
    }

    it('writes both documents for an app whose dashboard declares a column count', function () {
        $manifest = gridManifest();

        expect((new AppDocs)->technical($manifest, 'https://example.test/r/rentas')->toMarkdown())
            ->toBeString()
            ->and((new AppDocs)->manual($manifest)->toMarkdown())
            ->toBeString();
    });

    it('reports the declared count rather than skipping the block', function () {
        // Silently ignoring the integer would have been the cheap fix; the
        // number is real and belongs in the sheet.
        expect((new AppDocs)->technical(gridManifest(), 'https://example.test/r/rentas')->toMarkdown())
            ->toContain('5 cols');
    });

    it('still counts a table\'s real column definitions', function () {
        $sheet = (new AppDocs)->technical(docsManifest(), 'https://example.test/r/rentas')->toMarkdown();

        expect($sheet)->toContain('cols');
    });
});
