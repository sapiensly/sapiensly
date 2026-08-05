<?php

namespace App\Services\Apps\Docs;

/**
 * The technical sheet: what this app is made of, with the ids.
 *
 * Its reader is someone who has to change the app — a developer, or the model
 * asked to. So it does the thing the manifest itself does badly: it resolves
 * the ids. A manifest is a graph flattened into JSON, and reading one means
 * holding four id-to-name maps in your head; every table here has the name
 * beside the id so a relation, a column or an action can be read in one pass.
 *
 * It ends with the pointers a patch is written against, because "how do I
 * change this" is the question that brought the reader here.
 */
final class TechnicalWriter
{
    public function __construct(
        private readonly ManifestReader $m,
        private readonly DocWords $w,
        private readonly ?string $runtimeUrl = null,
    ) {}

    public function write(): Doc
    {
        $sections = array_values(array_filter([
            $this->section('identity', $this->w->get('s_identity'), $this->identity()),
            $this->section('model', $this->w->get('s_model'), $this->model()),
            $this->section('relations', $this->w->get('s_relations'), $this->relations()),
            $this->section('pages', $this->w->get('s_pages'), $this->pages()),
            $this->section('actions', $this->w->get('s_actions'), $this->actions()),
            $this->section('workflows', $this->w->get('s_workflows'), $this->workflows()),
            $this->section('permissions', $this->w->get('s_permissions'), $this->permissions()),
            $this->section('runtime', $this->w->get('s_runtime'), $this->runtime()),
        ]));

        return new Doc(
            kind: 'technical',
            title: $this->w->get('tech_title'),
            subject: $this->w->get('tech_subject', ['app' => (string) ($this->m->manifest['name'] ?? '')]),
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
    private function identity(): array
    {
        $manifest = $this->m->manifest;

        $fields = 0;
        foreach ($this->m->objects() as $object) {
            $fields += count($object['fields'] ?? []);
        }

        $items = [
            ['k' => $this->w->get('k_slug'), 'v' => (string) ($manifest['slug'] ?? '')],
            ['k' => $this->w->get('k_id'), 'v' => (string) ($manifest['id'] ?? '')],
            ['k' => $this->w->get('k_version'), 'v' => (string) ($manifest['version'] ?? '')],
            ['k' => $this->w->get('k_schema'), 'v' => (string) ($manifest['schema_version'] ?? '')],
            ['k' => $this->w->get('k_locale'), 'v' => (string) $this->m->setting('default_locale', '—')],
            ['k' => $this->w->get('k_currency'), 'v' => (string) $this->m->setting('default_currency', '—')],
            ['k' => $this->w->get('k_timezone'), 'v' => (string) $this->m->setting('default_timezone', '—')],
            ['k' => $this->w->get('k_counts'), 'v' => $this->w->get('k_counts_v', [
                'o' => count($this->m->objects()),
                'p' => count($this->m->pages()),
                'f' => $fields,
                'w' => count($this->m->workflows()),
            ])],
        ];

        $theme = array_values(array_filter([
            (string) $this->m->setting('theme', ''),
            (string) $this->m->setting('palette_mode', ''),
            (string) $this->m->setting('density', ''),
            (string) $this->m->setting('accent', ''),
        ]));
        if ($theme !== []) {
            $items[] = ['k' => $this->w->get('k_theme'), 'v' => implode(' · ', $theme)];
        }

        return [['type' => 'kv', 'items' => $items]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function model(): array
    {
        $body = [];

        foreach ($this->m->objects() as $object) {
            $body[] = ['type' => 'h', 'text' => (string) ($object['name'] ?? '')];

            $facts = [
                ['k' => $this->w->get('col_slug'), 'v' => (string) ($object['slug'] ?? '')],
                ['k' => $this->w->get('col_id'), 'v' => (string) ($object['id'] ?? '')],
            ];

            $body[] = ['type' => 'kv', 'items' => $facts];

            $display = $this->m->fieldName($object['primary_display_field_id'] ?? null, '');
            if ($display !== '') {
                $body[] = ['type' => 'p', 'text' => $this->w->get('obj_display', ['f' => $display])];
            }

            $rows = [];
            foreach ($object['fields'] ?? [] as $field) {
                $rows[] = [
                    (string) ($field['name'] ?? ''),
                    (string) ($field['slug'] ?? ''),
                    (string) ($field['type'] ?? ''),
                    ($field['required'] ?? false) === true ? $this->w->get('yes') : '',
                    $this->fieldDetail($field),
                ];
            }

            if ($rows !== []) {
                $body[] = [
                    'type' => 'table',
                    'head' => [
                        $this->w->get('col_name'),
                        $this->w->get('col_slug'),
                        $this->w->get('col_type'),
                        $this->w->get('col_required'),
                        $this->w->get('col_detail'),
                    ],
                    'rows' => $rows,
                ];
            }
        }

        return $body;
    }

    /**
     * Whatever a field carries beyond its type: its options, what it points at,
     * what it is computed from.
     *
     * @param  array<string, mixed>  $field
     */
    private function fieldDetail(array $field): string
    {
        $type = (string) ($field['type'] ?? '');

        if ($type === 'single_select' || $type === 'multi_select') {
            $values = array_map(
                fn ($o): string => (string) (is_array($o) ? ($o['value'] ?? '') : $o),
                $field['options'] ?? [],
            );

            return implode(' · ', array_filter($values));
        }

        if ($type === 'relation') {
            $target = $this->m->object($field['target_object_id'] ?? null);
            $parts = [(string) ($field['cardinality'] ?? '')];
            if ($target !== null) {
                $parts[] = '→ '.$target['slug'];
            }
            if (isset($field['on_delete'])) {
                $parts[] = 'on_delete='.$field['on_delete'];
            }

            return implode(' ', array_filter($parts));
        }

        // A computed field keeps its wiring at the TOP level (`aggregator`,
        // `via_relation_field_id`, `target_field_id`), not under `config`.
        $parts = [];
        $describes = ['aggregator', 'via_relation_field_id', 'target_field_id', 'expression', 'source_field_id', 'relation_field_id'];

        foreach ($describes as $key) {
            $value = $field[$key] ?? ($field['config'][$key] ?? null);
            if (! is_scalar($value)) {
                continue;
            }

            // Resolve the ids: `via_relation_field_id=fld_01k…` says nothing.
            $parts[] = str_ends_with($key, '_field_id')
                ? str_replace('_field_id', '', $key).'='.$this->m->fieldName((string) $value, (string) $value)
                : $key.'='.$value;
        }

        return implode(' · ', $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relations(): array
    {
        $rows = [];

        foreach ($this->m->objects() as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (($field['type'] ?? '') !== 'relation') {
                    continue;
                }

                // One row per relation, from the side that holds the key.
                if (($field['cardinality'] ?? '') === 'one_to_many') {
                    continue;
                }

                $target = $this->m->object($field['target_object_id'] ?? null);

                $rows[] = [
                    (string) ($object['name'] ?? '').' ('.($object['slug'] ?? '').')',
                    (string) ($field['name'] ?? '').' ('.($field['slug'] ?? '').')',
                    (string) ($field['cardinality'] ?? ''),
                    $target === null ? '—' : (string) ($target['name'] ?? '').' ('.($target['slug'] ?? '').')',
                    (string) ($field['on_delete'] ?? '—'),
                ];
            }
        }

        if ($rows === []) {
            return [['type' => 'p', 'text' => $this->w->get('rel_none')]];
        }

        return [
            [
                'type' => 'table',
                'head' => [
                    $this->w->get('col_from'),
                    $this->w->get('col_field2'),
                    $this->w->get('col_card'),
                    $this->w->get('col_to'),
                    $this->w->get('col_on_delete'),
                ],
                'rows' => $rows,
            ],
            ['type' => 'note', 'text' => $this->w->get('rel_note')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(): array
    {
        $body = [];

        foreach ($this->m->pages() as $index => $page) {
            $entries = $this->m->blocksOf($page);

            $body[] = ['type' => 'h', 'text' => (string) ($page['name'] ?? '')];
            $body[] = ['type' => 'kv', 'items' => [
                ['k' => $this->w->get('page_path'), 'v' => (string) ($page['path'] ?? '')],
                ['k' => $this->w->get('col_slug'), 'v' => (string) ($page['slug'] ?? '')],
                ['k' => $this->w->get('col_id'), 'v' => (string) ($page['id'] ?? '')],
                ['k' => $this->w->get('page_blocks', ['n' => count($entries)]), 'v' => '/pages/'.$index],
            ]];

            if ($entries === []) {
                continue;
            }

            $body[] = [
                'type' => 'tree',
                'items' => array_map(
                    fn (array $e): array => [
                        'depth' => $e['depth'],
                        'text' => (string) $e['block']['type'].$this->blockSubject($e['block']),
                        'meta' => (string) ($e['block']['id'] ?? ''),
                    ],
                    $entries,
                ),
            ];
        }

        return $body;
    }

    /**
     * What a block is pointed at, in one phrase — the object it reads, the
     * field it groups by, the label it carries.
     *
     * @param  array<string, mixed>  $block
     */
    private function blockSubject(array $block): string
    {
        $parts = [];

        $object = $this->m->object($this->m->objectIdOf($block));
        if ($object !== null) {
            $parts[] = (string) ($object['slug'] ?? '');
        }

        foreach (['group_by_field_id', 'date_field_id', 'card_title_field_id', 'via_relation_field_id'] as $key) {
            if (isset($block[$key])) {
                $parts[] = str_replace('_field_id', '', $key).'='.$this->m->fieldName((string) $block[$key]);
            }
        }

        $columnCount = ManifestReader::columnCountOf($block);
        if ($columnCount !== null) {
            $parts[] = $columnCount.' cols';
        }
        if (isset($block['items'])) {
            $parts[] = count($block['items']).' items';
        }
        if (isset($block['record_id_expression'])) {
            $parts[] = (string) $block['record_id_expression'];
        }

        $label = trim((string) ($block['label'] ?? $block['title'] ?? ''));
        if ($label !== '') {
            array_unshift($parts, '«'.$label.'»');
        }

        return $parts === [] ? '' : ' — '.implode(', ', $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actions(): array
    {
        $rows = [];

        foreach ($this->m->allBlocks() as $entry) {
            $block = $entry['block'];
            $where = (string) ($entry['page']['name'] ?? '');
            // A form has no label; its modal's title is what tells the two on
            // an object's page apart ("Add Owner" from "Edit Owner").
            $label = trim((string) ($block['label'] ?? $block['title'] ?? ''))
                ?: ($entry['parent_label'] ?? (string) $block['type']);

            foreach ([['on_click', 'trg_click'], ['on_submit', 'trg_submit']] as [$key, $word]) {
                // One row per control, not per step: the sequence IS the answer
                // to "what does pressing this do", and splitting it across four
                // rows buries the create_record among three housekeeping steps.
                $sequence = $this->describeSequence($block[$key] ?? null);
                if ($sequence !== '') {
                    $rows[] = [$where.' › '.$label, $this->w->get($word), $sequence];
                }
            }

            // The per-row buttons of a table carry their own actions.
            foreach (ManifestReader::columnsOf($block) as $column) {
                if (! is_array($column) || ($column['type'] ?? '') !== 'action') {
                    continue;
                }
                $sequence = $this->describeSequence($column['on_click'] ?? null);
                if ($sequence !== '') {
                    $rows[] = [
                        $where.' › '.trim((string) ($column['label'] ?? '')),
                        $this->w->get('trg_click'),
                        $sequence,
                    ];
                }
            }
        }

        if ($rows === []) {
            return [['type' => 'p', 'text' => $this->w->get('act_none')]];
        }

        return [
            [
                'type' => 'table',
                'head' => [$this->w->get('col_where'), $this->w->get('col_trigger'), $this->w->get('col_does')],
                'rows' => $rows,
            ],
            ['type' => 'note', 'text' => $this->w->get('act_note')],
        ];
    }

    /**
     * A whole action sequence on one line, in the order it runs.
     *
     * @param  mixed  $actions
     */
    private function describeSequence($actions): string
    {
        $described = [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            if (is_array($action)) {
                $described[] = $this->describeAction($action);
            }
        }

        return implode(' → ', $described);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function describeAction(array $action): string
    {
        $type = (string) ($action['type'] ?? '');
        $parts = [$type];

        $object = $this->m->object($action['object_id'] ?? null);
        if ($object !== null) {
            $parts[] = (string) ($object['slug'] ?? '');
        }

        if (isset($action['values']) && is_array($action['values'])) {
            $parts[] = count($action['values']).' values';
        }
        if (isset($action['path'])) {
            $parts[] = (string) $action['path'];
        }
        if (isset($action['message'])) {
            $parts[] = '«'.$action['message'].'»';
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workflows(): array
    {
        $workflows = $this->m->workflows();
        if ($workflows === []) {
            return [['type' => 'p', 'text' => $this->w->get('wf_none')]];
        }

        $rows = [];
        foreach ($workflows as $workflow) {
            $trigger = is_array($workflow['trigger'] ?? null) ? $workflow['trigger'] : [];
            $object = $this->m->object($trigger['object_id'] ?? null);

            $steps = array_map(
                fn ($s): string => is_array($s) ? (string) ($s['type'] ?? '?') : '?',
                $workflow['steps'] ?? [],
            );

            $rows[] = [
                (string) ($workflow['name'] ?? ''),
                (string) ($trigger['type'] ?? '').($object !== null ? ' · '.$object['slug'] : ''),
                isset($trigger['filter']) ? json_encode($trigger['filter'], JSON_UNESCAPED_UNICODE) : '—',
                implode(' → ', $steps),
            ];
        }

        return [[
            'type' => 'table',
            'head' => [
                $this->w->get('col_name'),
                $this->w->get('col_when'),
                $this->w->get('col_only_if'),
                $this->w->get('col_steps'),
            ],
            'rows' => $rows,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function permissions(): array
    {
        $roles = array_values(array_filter($this->m->manifest['permissions']['roles'] ?? [], is_array(...)));
        if ($roles === []) {
            return [];
        }

        $policies = array_values(array_filter(
            $this->m->manifest['permissions']['object_policies'] ?? [],
            is_array(...),
        ));

        $head = [$this->w->get('col_object')];
        foreach ($roles as $role) {
            $head[] = (string) ($role['name'] ?? '');
        }

        $rows = [];
        foreach ($this->m->objects() as $object) {
            $row = [(string) ($object['slug'] ?? '')];

            foreach ($roles as $role) {
                $actions = [];
                foreach ($policies as $policy) {
                    if (($policy['object_id'] ?? null) === ($object['id'] ?? null)
                        && ($policy['role_id'] ?? null) === ($role['id'] ?? null)) {
                        $actions = array_map(
                            fn ($a): string => strtoupper(substr((string) $a, 0, 1)),
                            $policy['actions'] ?? [],
                        );
                    }
                }
                $row[] = $actions === [] ? '—' : implode('', $actions);
            }

            $rows[] = $row;
        }

        $body = [];
        if ($rows !== []) {
            $body[] = ['type' => 'table', 'head' => $head, 'rows' => $rows];
        }
        $body[] = ['type' => 'note', 'text' => $this->w->get('perm_note')];

        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtime(): array
    {
        $body = [];

        if ($this->runtimeUrl !== null) {
            $body[] = ['type' => 'p', 'text' => $this->w->get('rt_url', ['url' => $this->runtimeUrl])];
        }

        $body[] = ['type' => 'p', 'text' => $this->w->get('rt_data')];
        $body[] = ['type' => 'p', 'text' => $this->w->get('rt_change')];

        $pointers = [];
        foreach ($this->m->objects() as $index => $object) {
            $pointers[] = ['k' => '/objects/'.$index, 'v' => (string) ($object['name'] ?? '')];
        }
        foreach ($this->m->pages() as $index => $page) {
            $pointers[] = ['k' => '/pages/'.$index, 'v' => (string) ($page['name'] ?? '')];
        }

        if ($pointers !== []) {
            $body[] = ['type' => 'h', 'text' => $this->w->get('rt_pointers')];
            $body[] = ['type' => 'kv', 'items' => $pointers];
        }

        $body[] = ['type' => 'note', 'text' => $this->w->get('rt_read')];

        return $body;
    }
}
