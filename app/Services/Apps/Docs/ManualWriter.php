<?php

namespace App\Services\Apps\Docs;

use App\Support\Locale\Inflector;

/**
 * The user guide: what this app is for, and how a person does each thing in it.
 *
 * Written from the manifest, deterministically, with no model call. That is the
 * whole design decision: a guide a model writes reads better on the day it is
 * written and is wrong by the third change, because nothing makes it follow the
 * app. This one is derived from the same document the runtime renders, so the
 * button it tells you to press is the button that is there.
 *
 * It answers in the order somebody actually asks: what is this for, what are
 * the screens, how do I do the thing, what does it keep, what does it do on its
 * own, who can do what.
 */
final class ManualWriter
{
    private readonly string $lang;

    public function __construct(
        private readonly ManifestReader $m,
        private readonly DocWords $w,
    ) {
        $this->lang = $w->lang();
    }

    public function write(): Doc
    {
        $name = (string) ($this->m->manifest['name'] ?? '');

        $sections = array_values(array_filter([
            $this->section('what', $this->w->get('s_what'), $this->what()),
            $this->section('screens', $this->w->get('s_screens'), $this->screens()),
            $this->section('tasks', $this->w->get('s_tasks'), $this->tasks()),
            $this->section('data', $this->w->get('s_data'), $this->data()),
            $this->section('auto', $this->w->get('s_auto'), $this->automations()),
            $this->section('who', $this->w->get('s_who'), $this->roles()),
            $this->section('tips', $this->w->get('s_tips'), $this->tips()),
        ]));

        return new Doc(
            kind: 'manual',
            title: $this->w->get('manual_title'),
            subject: $this->w->get('manual_subject', ['app' => $name]),
            sections: $sections,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $body
     * @return array{id: string, heading: string, body: list<array<string, mixed>>}|null
     */
    private function section(string $id, string $heading, array $body): ?array
    {
        return $body === [] ? null : ['id' => $id, 'heading' => $heading, 'body' => $body];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function what(): array
    {
        $body = [];

        $description = trim((string) ($this->m->manifest['description'] ?? ''));
        if ($description !== '') {
            $body[] = ['type' => 'p', 'text' => $description];
        }

        $objects = $this->m->objects();
        $pages = $this->m->pages();
        if ($objects === []) {
            return $body;
        }

        $body[] = ['type' => 'p', 'text' => $this->w->get('what_counts', [
            'kinds' => count($objects),
            'pages' => count($pages),
        ])];

        $body[] = ['type' => 'p', 'text' => $this->w->get('what_holds', [
            'list' => $this->w->list(array_map(
                fn (array $o): string => (string) ($o['name'] ?? ''),
                $objects,
            )),
        ])];

        $home = $pages[0] ?? null;
        if ($home !== null && ($home['path'] ?? '') === '/') {
            $body[] = ['type' => 'p', 'text' => $this->w->get('what_home', [
                'page' => (string) ($home['name'] ?? ''),
            ])];
        }

        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function screens(): array
    {
        $body = [];

        foreach ($this->m->pages() as $page) {
            $shows = $this->whatThePageShows($page);
            $can = $this->whatYouCanDoHere($page);

            if ($shows === [] && $can === []) {
                continue;
            }

            $body[] = ['type' => 'h', 'text' => (string) ($page['name'] ?? '')];
            $body[] = ['type' => 'p', 'text' => $this->w->get('screen_path', [
                'path' => (string) ($page['path'] ?? '/'),
            ])];

            if ($shows !== []) {
                $body[] = ['type' => 'p', 'text' => $this->w->get('screen_shows', [
                    'list' => $this->w->list($shows),
                ])];
            }

            if ($can !== []) {
                $body[] = ['type' => 'p', 'text' => $this->w->get('screen_actions')];
                $body[] = ['type' => 'ul', 'items' => $can];
            }
        }

        return $body;
    }

    /**
     * The page described as things a person sees, not as block types.
     *
     * @param  array<string, mixed>  $page
     * @return list<string>
     */
    private function whatThePageShows(array $page): array
    {
        $shows = [];
        $charts = 0;
        $metrics = 0;
        $sawText = false;

        foreach ($this->m->blocksOf($page) as $entry) {
            // A modal is not what the screen shows — it is what a button opens,
            // and it is described under the task that opens it.
            if ($entry['parent'] === 'modal') {
                continue;
            }

            $block = $entry['block'];
            $type = (string) $block['type'];
            $objectName = $this->m->objectName($this->m->objectIdOf($block));

            switch ($type) {
                case 'table':
                case 'data_grid':
                    $shows[] = $this->w->get('w_table', ['n' => $objectName]);
                    break;
                case 'kanban':
                    $shows[] = $this->w->get('w_board', [
                        'n' => $objectName,
                        'f' => $this->m->fieldName($block['group_by_field_id'] ?? null),
                    ]);
                    break;
                case 'calendar':
                    $shows[] = $this->w->get('w_calendar', [
                        'n' => $objectName,
                        'f' => $this->m->fieldName($block['date_field_id'] ?? $block['start_field_id'] ?? null),
                    ]);
                    break;
                case 'gantt':
                case 'timeline':
                    $shows[] = $this->w->get('w_timeline', ['n' => $objectName]);
                    break;
                case 'chart':
                case 'pivot':
                case 'funnel':
                case 'heatmap':
                case 'word_cloud':
                case 'sparkline':
                    $charts++;
                    break;
                case 'stat':
                case 'gauge':
                case 'progress':
                case 'insight':
                    $metrics++;
                    break;
                case 'metric_grid':
                    $metrics += max(1, count($block['items'] ?? []));
                    break;
                case 'record_detail':
                    $shows[] = $this->w->get('w_detail', [
                        's' => Inflector::singular($objectName, $this->lang),
                    ]);
                    break;
                case 'related_list':
                    $shows[] = $this->w->get('w_related', ['n' => $objectName]);
                    break;
                case 'form':
                case 'multi_step_form':
                    $shows[] = $this->w->get('w_form');
                    break;
                case 'filter_bar':
                    $shows[] = $this->w->get('w_filters');
                    break;
                case 'text':
                case 'markdown':
                    $sawText = true;
                    break;
            }
        }

        if ($metrics > 0) {
            array_unshift($shows, $metrics === 1
                ? $this->w->get('w_metric')
                : $this->w->get('w_metrics', ['c' => $metrics]));
        }

        if ($charts > 0) {
            $shows[] = $charts === 1
                ? $this->w->get('w_chart')
                : $this->w->get('w_charts', ['c' => $charts]);
        }

        if ($shows === [] && $sawText) {
            $shows[] = $this->w->get('w_text');
        }

        return array_values(array_unique($shows));
    }

    /**
     * The labelled things a person can press on this page.
     *
     * @param  array<string, mixed>  $page
     * @return list<string>
     */
    private function whatYouCanDoHere(array $page): array
    {
        $labels = [];

        foreach ($this->m->blocksOf($page) as $entry) {
            if ($entry['parent'] === 'modal') {
                continue;
            }

            $block = $entry['block'];

            if ((string) $block['type'] === 'button') {
                $label = trim((string) ($block['label'] ?? ''));
                if ($label !== '') {
                    $labels[] = $label;
                }
            }

            // Action columns: the per-row buttons of a table.
            foreach ($block['columns'] ?? [] as $column) {
                if (is_array($column) && ($column['type'] ?? '') === 'action') {
                    $label = trim((string) ($column['label'] ?? ''));
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * The heart of the guide: one recipe per thing the app lets you record.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(): array
    {
        $body = [];

        foreach ($this->m->objects() as $object) {
            $recipe = $this->recipeFor($object);
            if ($recipe === []) {
                continue;
            }

            foreach ($recipe as $block) {
                $body[] = $block;
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return list<array<string, mixed>>
     */
    private function recipeFor(array $object): array
    {
        $objectId = (string) ($object['id'] ?? '');
        $objectName = (string) ($object['name'] ?? '');
        $singular = Inflector::singular($objectName, $this->lang);

        $create = $this->findCreateForm($objectId);
        if ($create === null) {
            return [];
        }

        [$page, $form, $opener] = $create;

        $steps = [$this->w->get('step_open_page', ['page' => (string) ($page['name'] ?? '')])];

        if ($opener !== null) {
            $steps[] = $this->w->get('step_press', ['button' => $opener]);
        }

        $required = $this->requiredFieldNames($object, $form);
        $steps[] = $required === []
            ? $this->w->get('step_fill_none')
            : $this->w->get('step_fill', ['list' => $this->w->list($required)]);

        $steps[] = $this->w->get('step_submit', [
            'label' => trim((string) ($form['submit_label'] ?? '')) ?: $this->w->get('yes'),
        ]);

        $body = [
            ['type' => 'h', 'text' => $this->w->get('task_add', ['s' => $singular])],
            ['type' => 'steps', 'items' => $steps],
        ];

        foreach ($this->afterwards($objectId, $page) as $line) {
            $body[] = ['type' => 'p', 'text' => $line];
        }

        return $body;
    }

    /**
     * The page and form where this object is created, plus the label of the
     * button that opens it (null when the form sits on the page itself).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null}|null
     */
    private function findCreateForm(string $objectId): ?array
    {
        foreach ($this->m->pages() as $page) {
            $entries = $this->m->blocksOf($page);

            foreach ($entries as $entry) {
                $block = $entry['block'];
                if (! in_array((string) $block['type'], ['form', 'multi_step_form'], true)) {
                    continue;
                }
                if ($this->m->objectIdOf($block) !== $objectId) {
                    continue;
                }
                if (($block['mode'] ?? 'create') !== 'create' && ! $this->createsRecord($block)) {
                    continue;
                }

                return [$page, $block, $this->openerLabel($entries, $entry)];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function createsRecord(array $block): bool
    {
        foreach ($block['on_submit'] ?? [] as $action) {
            if (is_array($action) && ($action['type'] ?? '') === 'create_record') {
                return true;
            }
        }

        return false;
    }

    /**
     * The label of whatever opens the modal this form sits in.
     *
     * @param  list<array{block: array<string, mixed>, depth: int, parent: string|null}>  $entries
     * @param  array{block: array<string, mixed>, depth: int, parent: string|null}  $formEntry
     */
    private function openerLabel(array $entries, array $formEntry): ?string
    {
        if ($formEntry['parent'] !== 'modal') {
            return null;
        }

        // Which modal holds it: the nearest preceding modal block that contains
        // this form. The walk is depth-first in reading order, so scanning back
        // for the last modal shallower than the form finds its own.
        $modalId = null;
        foreach ($entries as $entry) {
            if ($entry['block'] === $formEntry['block']) {
                break;
            }
            if ((string) $entry['block']['type'] === 'modal' && $entry['depth'] < $formEntry['depth']) {
                $modalId = (string) ($entry['block']['id'] ?? '');
            }
        }

        if ($modalId === null) {
            return null;
        }

        foreach ($entries as $entry) {
            $block = $entry['block'];
            if ((string) $block['type'] !== 'button') {
                continue;
            }
            foreach ($block['on_click'] ?? [] as $action) {
                if (is_array($action) && ($action['modal_block_id'] ?? null) === $modalId) {
                    return trim((string) ($block['label'] ?? '')) ?: null;
                }
            }
        }

        return null;
    }

    /**
     * The fields of this form the app will refuse to save without.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $form
     * @return list<string>
     */
    private function requiredFieldNames(array $object, array $form): array
    {
        $onForm = [];
        foreach ($form['fields'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['field_id'])) {
                $onForm[] = (string) $entry['field_id'];
            }
        }

        // A multi-step form carries its fields per step.
        foreach ($form['steps'] ?? [] as $step) {
            foreach ((is_array($step) ? $step['fields'] ?? [] : []) as $entry) {
                if (is_array($entry) && isset($entry['field_id'])) {
                    $onForm[] = (string) $entry['field_id'];
                }
            }
        }

        $names = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }
            if ($onForm !== [] && ! in_array((string) ($field['id'] ?? ''), $onForm, true)) {
                continue;
            }
            $names[] = (string) ($field['name'] ?? '');
        }

        return array_values(array_filter($names));
    }

    /**
     * What else this object affords once a record exists: correcting it,
     * removing it, dragging it across a board, opening its own page.
     *
     * @param  array<string, mixed>  $listPage
     * @return list<string>
     */
    private function afterwards(string $objectId, array $listPage): array
    {
        $lines = [];
        $entries = $this->m->blocksOf($listPage);

        foreach ($entries as $entry) {
            $block = $entry['block'];

            foreach ($block['columns'] ?? [] as $column) {
                if (! is_array($column) || ($column['type'] ?? '') !== 'action') {
                    continue;
                }

                $label = trim((string) ($column['label'] ?? ''));
                if ($label === '') {
                    continue;
                }

                $kinds = $this->actionKinds($column['on_click'] ?? []);
                if (in_array('delete_record', $kinds, true)) {
                    $lines[] = $this->w->get('task_delete', ['label' => $label]);
                } elseif (in_array('navigate', $kinds, true)) {
                    $lines[] = $this->w->get('task_open', ['label' => $label]);
                } elseif (in_array('open_modal', $kinds, true)) {
                    $lines[] = $this->w->get('task_edit', ['label' => $label]);
                }
            }

            if ((string) $block['type'] === 'kanban'
                && $this->m->objectIdOf($block) === $objectId
                && ($block['editable'] ?? false) === true) {
                $lines[] = $this->w->get('task_board', [
                    'f' => $this->m->fieldName($block['group_by_field_id'] ?? null),
                ]);
            }
        }

        // The record's own page, and what it lists underneath.
        $children = $this->childListsOn($objectId);
        if ($children !== []) {
            $lines[] = $this->w->get('task_detail_children', ['list' => $this->w->list($children)]);
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param  mixed  $actions
     * @return list<string>
     */
    private function actionKinds($actions): array
    {
        $kinds = [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            if (is_array($action) && isset($action['type'])) {
                $kinds[] = (string) $action['type'];
            }
        }

        return $kinds;
    }

    /**
     * The objects listed underneath a record of this one, on its detail page.
     *
     * @return list<string>
     */
    private function childListsOn(string $objectId): array
    {
        $children = [];

        foreach ($this->m->allBlocks() as $entry) {
            $block = $entry['block'];
            if ((string) $block['type'] !== 'related_list') {
                continue;
            }

            // Whose page is this? The detail block on the same page names it.
            foreach ($this->m->blocksOf($entry['page']) as $sibling) {
                if ((string) $sibling['block']['type'] === 'record_detail'
                    && $this->m->objectIdOf($sibling['block']) === $objectId) {
                    $children[] = $this->m->objectName($this->m->objectIdOf($block));
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($children)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function data(): array
    {
        $body = [];
        $currency = (string) $this->m->setting('default_currency', '');

        foreach ($this->m->objects() as $object) {
            $rows = [];

            foreach ($object['fields'] ?? [] as $field) {
                // The one-side of a relation holds nothing and is not filled in
                // by anybody — it exists so the other end can be found.
                if (($field['type'] ?? '') === 'relation' && ($field['cardinality'] ?? '') === 'one_to_many') {
                    continue;
                }

                $rows[] = [
                    (string) ($field['name'] ?? ''),
                    $this->plainType($field, $currency),
                    ($field['required'] ?? false) === true ? $this->w->get('yes') : $this->w->get('no'),
                ];
            }

            if ($rows === []) {
                continue;
            }

            $body[] = ['type' => 'h', 'text' => (string) ($object['name'] ?? '')];
            $body[] = ['type' => 'p', 'text' => $this->w->get('data_intro', [
                's' => Inflector::singular((string) ($object['name'] ?? ''), $this->lang),
            ])];
            $body[] = [
                'type' => 'table',
                'head' => [$this->w->get('col_field'), $this->w->get('col_holds'), $this->w->get('col_required')],
                'rows' => $rows,
            ];
        }

        return $body;
    }

    /**
     * A field type said the way somebody filling the form would say it.
     *
     * @param  array<string, mixed>  $field
     */
    private function plainType(array $field, string $currency): string
    {
        $type = (string) ($field['type'] ?? '');

        if ($type === 'single_select' || $type === 'multi_select') {
            $labels = array_map(
                fn ($o): string => (string) (is_array($o) ? ($o['label'] ?? $o['value'] ?? '') : $o),
                $field['options'] ?? [],
            );

            return $this->w->get('t_'.$type, ['list' => implode(', ', array_filter($labels))]);
        }

        if ($type === 'relation') {
            $target = $this->m->objectName($field['target_object_id'] ?? null);
            $target = $target !== '' ? $target : (string) ($field['name'] ?? '');

            // "belongs to one Owner", not "one Owners" — the object is named in
            // the plural and only one of it is meant here.
            return ($field['cardinality'] ?? '') === 'one_to_many'
                ? $this->w->get('t_relation_many', ['n' => $target])
                : $this->w->get('t_relation_one', ['n' => Inflector::singular($target, $this->lang)]);
        }

        if ($type === 'rollup' || $type === 'lookup') {
            $through = $this->acrossRelation($field);
            if ($through === '') {
                return $this->w->get('t_formula');
            }

            // A sum is of a FIELD on the children, not of the children — "the
            // total of its Properties" is not a quantity anybody recognises.
            $of = $this->m->fieldName($field['target_field_id'] ?? null, '');
            if (($field['aggregator'] ?? '') === 'sum' && $of !== '') {
                return $this->w->get('t_rollup_sum_of', ['f' => $of, 'n' => $through]);
            }

            $key = match ((string) ($field['aggregator'] ?? '')) {
                'count', 'count_distinct' => 't_rollup_count',
                'sum' => 't_rollup_sum',
                default => 't_'.$type,
            };

            return $this->w->get($key, ['n' => $through]);
        }

        if ($type === 'currency') {
            return $this->w->get('t_currency', ['c' => $currency !== '' ? $currency : '—']);
        }

        $key = 't_'.$type;

        return $key === 't_' ? $this->w->get('t_unknown') : $this->w->get($key);
    }

    /**
     * The object on the far side of the relation a rollup or lookup reads over.
     *
     * The manifest keeps this at the top level of the field — `aggregator` and
     * `via_relation_field_id`, not a `config` map — and reading it from the
     * wrong place is silent: every computed field on a real app came out as
     * "worked out from its " with an empty noun where the object should be.
     *
     * @param  array<string, mixed>  $field
     */
    private function acrossRelation(array $field): string
    {
        $via = $this->m->field($field['via_relation_field_id'] ?? null);
        if ($via === null) {
            return '';
        }

        return $this->m->objectName($via['target_object_id'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function automations(): array
    {
        $workflows = $this->m->workflows();
        if ($workflows === []) {
            return [];
        }

        $lines = [];
        foreach ($workflows as $workflow) {
            if (($workflow['enabled'] ?? true) === false) {
                continue;
            }

            $steps = array_values(array_filter(array_map(
                fn ($s): string => is_array($s) ? trim((string) ($s['name'] ?? $s['type'] ?? '')) : '',
                $workflow['steps'] ?? [],
            )));

            if ($steps === []) {
                continue;
            }

            $lines[] = $this->w->get('auto_line', [
                'trigger' => $this->plainTrigger($workflow['trigger'] ?? []),
                'steps' => $this->w->list($steps),
            ]);
        }

        if ($lines === []) {
            return [];
        }

        return [
            ['type' => 'p', 'text' => $this->w->get('auto_intro')],
            ['type' => 'ul', 'items' => $lines],
        ];
    }

    /**
     * @param  mixed  $trigger
     */
    private function plainTrigger($trigger): string
    {
        if (! is_array($trigger)) {
            return $this->w->get('trg_other');
        }

        $type = (string) ($trigger['type'] ?? '');
        $objectName = $this->m->objectName($trigger['object_id'] ?? null);
        $singular = Inflector::singular($objectName, $this->lang);

        return match ($type) {
            'record.created' => $this->w->get('trg_created', ['s' => $singular]),
            'record.updated' => $this->w->get('trg_updated', ['s' => $singular]),
            'record.deleted' => $this->w->get('trg_deleted', ['s' => $singular]),
            'schedule' => $this->w->get('trg_schedule'),
            'webhook' => $this->w->get('trg_webhook'),
            'manual' => $this->w->get('trg_manual'),
            default => $this->w->get('trg_other'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roles(): array
    {
        $roles = array_values(array_filter($this->m->manifest['permissions']['roles'] ?? [], is_array(...)));
        if ($roles === []) {
            return [];
        }

        $policies = array_values(array_filter(
            $this->m->manifest['permissions']['object_policies'] ?? [],
            is_array(...),
        ));

        $rows = [];
        $default = null;

        foreach ($roles as $role) {
            $actions = [];
            foreach ($policies as $policy) {
                if (($policy['role_id'] ?? null) !== ($role['id'] ?? null)) {
                    continue;
                }
                foreach ($policy['actions'] ?? [] as $action) {
                    $actions[(string) $action] = true;
                }
            }

            $rows[] = [
                (string) ($role['name'] ?? ''),
                $this->plainActions(array_keys($actions)),
            ];

            if (($role['is_default'] ?? false) === true) {
                $default = (string) ($role['name'] ?? '');
            }
        }

        $body = [
            ['type' => 'p', 'text' => $this->w->get('who_intro', ['n' => count($roles)])],
            [
                'type' => 'table',
                'head' => [$this->w->get('col_role'), $this->w->get('col_may')],
                'rows' => $rows,
            ],
        ];

        if ($default !== null) {
            $body[] = ['type' => 'p', 'text' => $this->w->get('who_default', ['role' => $default])];
        }

        return $body;
    }

    /**
     * @param  list<string>  $actions
     */
    private function plainActions(array $actions): string
    {
        if ($actions === []) {
            return $this->w->get('p_none');
        }

        $all = ['create', 'read', 'update', 'delete'];
        if (count(array_intersect($all, $actions)) === count($all)) {
            return $this->w->get('p_all');
        }

        $words = [];
        foreach ($all as $action) {
            if (in_array($action, $actions, true)) {
                $words[] = $this->w->get('p_'.$action);
            }
        }

        return $this->w->list($words);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tips(): array
    {
        $tips = [];

        // Only the ones this app can actually do. A guide that describes a
        // search box on an app with no table is a guide nobody trusts twice.
        $hasTable = false;
        foreach ($this->m->allBlocks() as $entry) {
            if (in_array((string) $entry['block']['type'], ['table', 'data_grid'], true)) {
                $hasTable = true;
                break;
            }
        }

        if ($hasTable) {
            $tips[] = $this->w->get('tip_search');
            $tips[] = $this->w->get('tip_sort');
            $tips[] = $this->w->get('tip_columns');
        }

        foreach ($this->m->objects() as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (($field['required'] ?? false) === true) {
                    $tips[] = $this->w->get('tip_required');
                    break 2;
                }
            }
        }

        $tips[] = $this->w->get('tip_versions');

        return [['type' => 'ul', 'items' => $tips]];
    }
}
