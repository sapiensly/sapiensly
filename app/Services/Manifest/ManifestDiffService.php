<?php

namespace App\Services\Manifest;

/**
 * Entity-aware semantic diff between two app manifests. A text/JSON diff drowns
 * the answer to "what did this build turn actually change?" in reordered keys and
 * generated ids; this matches entities by their stable id and reports the changes
 * that carry meaning — objects/fields/pages/roles added, removed or modified, and
 * for a modified field exactly which properties (type, cardinality, options, …)
 * moved. Pure and side-effect-free: it reads two manifest arrays and returns a
 * structured report plus a rolled-up count summary.
 */
class ManifestDiffService
{
    /** Field properties whose change is a meaningful modification worth surfacing. */
    private const FIELD_KEYS = [
        'name', 'slug', 'type', 'cardinality', 'target_object_id',
        'target_field_id', 'via_relation_field_id', 'aggregator', 'readonly', 'currency_code',
    ];

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    public function diff(array $from, array $to): array
    {
        $objects = $this->diffObjects($from['objects'] ?? [], $to['objects'] ?? []);
        $pages = $this->diffPages($from['pages'] ?? [], $to['pages'] ?? []);
        $roles = $this->diffRoles($from['permissions']['roles'] ?? [], $to['permissions']['roles'] ?? []);
        $settings = $this->diffSettings($from['settings'] ?? [], $to['settings'] ?? []);

        return [
            'summary' => $this->summarize($objects, $pages, $roles, $settings),
            'objects' => $this->prune($objects),
            'pages' => $this->prune($pages),
            'roles' => $this->prune($roles),
            'settings' => $settings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}
     */
    private function diffObjects(array $from, array $to): array
    {
        $fromById = $this->byId($from);
        $toById = $this->byId($to);

        $added = [];
        foreach ($toById as $id => $object) {
            if (! isset($fromById[$id])) {
                $added[] = $this->objectRef($object) + ['field_count' => count($object['fields'] ?? [])];
            }
        }

        $removed = [];
        $modified = [];
        foreach ($fromById as $id => $object) {
            if (! isset($toById[$id])) {
                $removed[] = $this->objectRef($object);

                continue;
            }

            $rename = $this->renameChanges($object, $toById[$id], ['name', 'slug']);
            $fields = $this->diffFields($object['fields'] ?? [], $toById[$id]['fields'] ?? []);

            if ($rename !== [] || $fields !== []) {
                $modified[] = array_filter([
                    ...$this->objectRef($toById[$id]),
                    'renamed' => $rename !== [] ? $rename : null,
                    'fields' => $fields !== [] ? $fields : null,
                ], fn ($v) => $v !== null);
            }
        }

        return ['added' => $added, 'removed' => $removed, 'modified' => $modified];
    }

    /**
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array<string, mixed>
     */
    private function diffFields(array $from, array $to): array
    {
        $fromById = $this->byId($from);
        $toById = $this->byId($to);

        $added = [];
        $modified = [];
        foreach ($toById as $id => $field) {
            if (! isset($fromById[$id])) {
                $added[] = $this->fieldRef($field);

                continue;
            }
            $changes = $this->fieldChanges($fromById[$id], $field);
            if ($changes !== []) {
                $modified[] = ['id' => $id, 'slug' => $field['slug'] ?? null, 'changes' => $changes];
            }
        }

        $removed = [];
        foreach ($fromById as $id => $field) {
            if (! isset($toById[$id])) {
                $removed[] = $this->fieldRef($field);
            }
        }

        return array_filter([
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ], fn ($v) => $v !== []);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function fieldChanges(array $a, array $b): array
    {
        $changes = [];
        foreach (self::FIELD_KEYS as $key) {
            $av = $a[$key] ?? null;
            $bv = $b[$key] ?? null;
            if ($av !== $bv) {
                $changes[$key] = ['from' => $av, 'to' => $bv];
            }
        }

        $addedOptions = array_values(array_diff($this->optionValues($b), $this->optionValues($a)));
        $removedOptions = array_values(array_diff($this->optionValues($a), $this->optionValues($b)));
        if ($addedOptions !== [] || $removedOptions !== []) {
            $changes['options'] = array_filter([
                'added' => $addedOptions,
                'removed' => $removedOptions,
            ], fn ($v) => $v !== []);
        }

        return $changes;
    }

    /**
     * Pages are matched by id: added/removed pages, plus, for a surviving page, a
     * rename and a block-level count (block ids added/removed anywhere in its
     * tree). Block CONTENT edits are intentionally not diffed — the signal here is
     * structural (a page gained a chart, lost a form), not textual.
     *
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}
     */
    private function diffPages(array $from, array $to): array
    {
        $fromById = $this->byId($from);
        $toById = $this->byId($to);

        $added = [];
        foreach ($toById as $id => $page) {
            if (! isset($fromById[$id])) {
                $added[] = $this->pageRef($page);
            }
        }

        $removed = [];
        $modified = [];
        foreach ($fromById as $id => $page) {
            if (! isset($toById[$id])) {
                $removed[] = $this->pageRef($page);

                continue;
            }

            $rename = $this->renameChanges($page, $toById[$id], ['name', 'path', 'slug']);
            $fromBlocks = $this->blockIds($page['blocks'] ?? []);
            $toBlocks = $this->blockIds($toById[$id]['blocks'] ?? []);
            $blocksAdded = count(array_diff($toBlocks, $fromBlocks));
            $blocksRemoved = count(array_diff($fromBlocks, $toBlocks));

            if ($rename !== [] || $blocksAdded > 0 || $blocksRemoved > 0) {
                $modified[] = array_filter([
                    ...$this->pageRef($toById[$id]),
                    'renamed' => $rename !== [] ? $rename : null,
                    'blocks_added' => $blocksAdded ?: null,
                    'blocks_removed' => $blocksRemoved ?: null,
                ], fn ($v) => $v !== null);
            }
        }

        return ['added' => $added, 'removed' => $removed, 'modified' => $modified];
    }

    /**
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>}
     */
    private function diffRoles(array $from, array $to): array
    {
        $fromById = $this->byId($from);
        $toById = $this->byId($to);

        $ref = fn (array $r): array => array_filter([
            'id' => $r['id'] ?? null,
            'slug' => $r['slug'] ?? null,
            'name' => $r['name'] ?? null,
        ], fn ($v) => $v !== null);

        $added = [];
        $modified = [];
        foreach ($toById as $id => $role) {
            if (! isset($fromById[$id])) {
                $added[] = $ref($role);

                continue;
            }
            $rename = $this->renameChanges($fromById[$id], $role, ['name', 'slug', 'is_default']);
            if ($rename !== []) {
                $modified[] = $ref($role) + ['changed' => $rename];
            }
        }

        $removed = [];
        foreach ($fromById as $id => $role) {
            if (! isset($toById[$id])) {
                $removed[] = $ref($role);
            }
        }

        return ['added' => $added, 'removed' => $removed, 'modified' => $modified];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{key: string, from: mixed, to: mixed}>
     */
    private function diffSettings(array $from, array $to): array
    {
        $changed = [];
        foreach (array_keys($from + $to) as $key) {
            $av = $from[$key] ?? null;
            $bv = $to[$key] ?? null;
            if ($av !== $bv) {
                $changed[] = ['key' => $key, 'from' => $av, 'to' => $bv];
            }
        }

        return $changed;
    }

    /**
     * Roll the per-section added/removed/modified lists up into flat counts, so a
     * caller can see the shape of the change at a glance before reading detail.
     *
     * @param  array{added: array<int, mixed>, removed: array<int, mixed>, modified: list<array<string, mixed>>}  $objects
     * @param  array{added: array<int, mixed>, removed: array<int, mixed>, modified: array<int, mixed>}  $pages
     * @param  array{added: array<int, mixed>, removed: array<int, mixed>, modified: array<int, mixed>}  $roles
     * @param  list<array<string, mixed>>  $settings
     * @return array<string, int>
     */
    private function summarize(array $objects, array $pages, array $roles, array $settings): array
    {
        $fieldsAdded = 0;
        $fieldsRemoved = 0;
        $fieldsModified = 0;
        foreach ($objects['modified'] as $object) {
            $fieldsAdded += count($object['fields']['added'] ?? []);
            $fieldsRemoved += count($object['fields']['removed'] ?? []);
            $fieldsModified += count($object['fields']['modified'] ?? []);
        }
        // A new object brings all its fields as additions.
        foreach ($objects['added'] as $object) {
            $fieldsAdded += (int) ($object['field_count'] ?? 0);
        }

        return array_filter([
            'objects_added' => count($objects['added']),
            'objects_removed' => count($objects['removed']),
            'objects_modified' => count($objects['modified']),
            'fields_added' => $fieldsAdded,
            'fields_removed' => $fieldsRemoved,
            'fields_modified' => $fieldsModified,
            'pages_added' => count($pages['added']),
            'pages_removed' => count($pages['removed']),
            'pages_modified' => count($pages['modified']),
            'roles_added' => count($roles['added']),
            'roles_removed' => count($roles['removed']),
            'roles_modified' => count($roles['modified']),
            'settings_changed' => count($settings),
        ], fn (int $v) => $v > 0);
    }

    /**
     * Drop empty added/removed/modified buckets so a section only carries what
     * actually changed.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function prune(array $section): array
    {
        return array_filter($section, fn ($v) => $v !== [] && $v !== null);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  list<string>  $keys
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function renameChanges(array $a, array $b, array $keys): array
    {
        $changes = [];
        foreach ($keys as $key) {
            $av = $a[$key] ?? null;
            $bv = $b[$key] ?? null;
            if ($av !== $bv) {
                $changes[$key] = ['from' => $av, 'to' => $bv];
            }
        }

        return $changes;
    }

    /**
     * Index a list of entities by their id, skipping any without one.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            if (isset($item['id'])) {
                $byId[$item['id']] = $item;
            }
        }

        return $byId;
    }

    /**
     * Every block id anywhere in a block tree (recursing the nested `blocks` of
     * containers like modals).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private function blockIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (isset($block['id'])) {
                $ids[] = $block['id'];
            }
            if (isset($block['blocks']) && is_array($block['blocks'])) {
                $ids = [...$ids, ...$this->blockIds($block['blocks'])];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function optionValues(array $field): array
    {
        $options = $field['options'] ?? null;
        if (! is_array($options)) {
            return [];
        }

        $values = array_map(static fn ($o): string => (string) ($o['value'] ?? ''), $options);
        sort($values);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function objectRef(array $object): array
    {
        return array_filter([
            'id' => $object['id'] ?? null,
            'slug' => $object['slug'] ?? null,
            'name' => $object['name'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function fieldRef(array $field): array
    {
        return array_filter([
            'id' => $field['id'] ?? null,
            'slug' => $field['slug'] ?? null,
            'name' => $field['name'] ?? null,
            'type' => $field['type'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function pageRef(array $page): array
    {
        return array_filter([
            'id' => $page['id'] ?? null,
            'slug' => $page['slug'] ?? null,
            'name' => $page['name'] ?? null,
            'path' => $page['path'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
