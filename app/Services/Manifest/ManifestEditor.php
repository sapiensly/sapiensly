<?php

namespace App\Services\Manifest;

use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * High-level, typed manifest edits — add an object (with its CRUD page), add a
 * field (wired into that object's table + create form) — as the reliable
 * alternative to hand-authored RFC 6902 patches via propose_change.
 *
 * Each operation reads the active manifest, mutates the PHP array, and saves a
 * full snapshot through {@see AppManifestService::createVersion}, which
 * validates before persisting — so a bad edit fails loudly with a legible
 * message and nothing is written, never a half-applied state.
 */
class ManifestEditor
{
    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppScaffolder $scaffolder,
    ) {}

    /**
     * Add a new object and, by default, a ready-to-use list+create page for it.
     *
     * @param  array<int, array<string, mixed>>  $rawFields  loose field specs ({name, slug?, type, options?})
     */
    public function addObject(App $app, string $name, ?string $slug, array $rawFields, bool $withPage = true, ?User $user = null): AppVersion
    {
        $manifest = $this->activeManifest($app);
        $currency = (string) ($manifest['settings']['default_currency'] ?? 'MXN');

        $objectSlug = $this->uniqueSlug($slug ?? $name, array_column($manifest['objects'] ?? [], 'slug'), 'object');

        $fields = $this->scaffolder->normalizeFields($rawFields);
        if ($fields === []) {
            $fields[] = ['name' => 'Name', 'slug' => 'name', 'type' => 'string', 'options' => null];
        }

        [$objectDef, $fieldIndex] = $this->scaffolder->buildObject(
            ['name' => $name, 'slug' => $objectSlug, 'fields' => $fields],
            $currency,
        );

        $manifest['objects'][] = $objectDef;

        if ($withPage) {
            $pageSlug = $this->uniqueSlug($objectSlug, array_column($manifest['pages'] ?? [], 'slug'), 'page');
            $manifest['pages'][] = $this->scaffolder->buildPage(
                ['name' => $name, 'slug' => $pageSlug],
                $objectDef['id'],
                $fieldIndex,
            );
        }

        return $this->manifests->createVersion($app, $manifest, $user, "Added object \"{$name}\"");
    }

    /**
     * Add a single field to an existing object, wiring it into that object's
     * table columns and create form so it is immediately usable.
     *
     * @param  array<string, mixed>  $rawField  a loose field spec ({name, slug?, type, options?})
     */
    public function addField(App $app, string $objectSlug, array $rawField, bool $addToPage = true, ?User $user = null): AppVersion
    {
        $manifest = $this->activeManifest($app);

        $objectIndex = $this->findObjectIndex($manifest, $objectSlug);
        $object = $manifest['objects'][$objectIndex];
        $currency = (string) ($manifest['settings']['default_currency'] ?? 'MXN');

        $field = $this->scaffolder->normalizeField($rawField, array_column($object['fields'] ?? [], 'slug'));
        if ($field === null) {
            throw new \InvalidArgumentException('The field spec was empty or invalid.');
        }

        [$definition, $indexEntry] = $this->scaffolder->buildField($field, $currency);
        $manifest['objects'][$objectIndex]['fields'][] = $definition;

        if ($addToPage) {
            // Computed fields (formula/lookup/rollup) are read-only — they belong in
            // tables but never in a create form.
            $formToo = ! in_array($field['type'], ['formula', 'lookup', 'rollup'], true);
            foreach ($manifest['pages'] as &$page) {
                if (isset($page['blocks']) && is_array($page['blocks'])) {
                    $this->injectFieldIntoBlocks($page['blocks'], $object['id'], $indexEntry['id'], $indexEntry['slug'], $formToo);
                }
            }
            unset($page);
        }

        return $this->manifests->createVersion($app, $manifest, $user, "Added field \"{$field['name']}\" to \"{$objectSlug}\"");
    }

    /**
     * Link two existing objects with a belongs-to relation: a $fromSlug record
     * belongs to one $toSlug record. Creates the bidirectional pair and wires the
     * picker into the $fromSlug create form + table.
     */
    public function addRelation(App $app, string $fromSlug, string $toSlug, ?string $name = null, bool $addToPage = true, ?User $user = null): AppVersion
    {
        $manifest = $this->activeManifest($app);

        if ($fromSlug === $toSlug) {
            throw new \InvalidArgumentException('A relation needs two different objects.');
        }
        $fromIndex = $this->findObjectIndex($manifest, $fromSlug);
        $toIndex = $this->findObjectIndex($manifest, $toSlug);

        $pair = $this->scaffolder->buildRelation(
            $manifest['objects'][$fromIndex],
            $manifest['objects'][$toIndex],
            $name,
        );

        $manifest['objects'][$fromIndex]['fields'][] = $pair['child_field'];
        $manifest['objects'][$toIndex]['fields'][] = $pair['parent_field'];
        $manifest['objects'][$toIndex]['fields'][] = $pair['parent_rollup_field'];

        if ($addToPage) {
            $fromObjectId = $manifest['objects'][$fromIndex]['id'];
            $toObjectId = $manifest['objects'][$toIndex]['id'];
            foreach ($manifest['pages'] as &$page) {
                if (! isset($page['blocks']) || ! is_array($page['blocks'])) {
                    continue;
                }
                // The picker goes on the child's form + table; the (read-only)
                // rollup count is a column on the parent's table only.
                $this->injectFieldIntoBlocks($page['blocks'], $fromObjectId, $pair['child_index']['id'], $pair['child_index']['slug']);
                $this->injectFieldIntoBlocks($page['blocks'], $toObjectId, $pair['parent_rollup_index']['id'], $pair['parent_rollup_index']['slug'], formToo: false);
            }
            unset($page);
        }

        return $this->manifests->createVersion($app, $manifest, $user, "Linked \"{$fromSlug}\" to \"{$toSlug}\"");
    }

    /**
     * Append a column to every table over $objectId and (when $formToo) a field
     * plus its create_record value mapping to every create form over $objectId,
     * walking nested modal/container blocks. Mutates $blocks in place. Pass
     * $formToo=false for read-only fields (rollups) that belong only in tables.
     *
     * @param  array<int, mixed>  $blocks
     */
    private function injectFieldIntoBlocks(array &$blocks, string $objectId, string $fieldId, string $slug, bool $formToo = true): void
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;

            if ($type === 'table' && ($block['data_source']['object_id'] ?? null) === $objectId) {
                $column = ['id' => $this->scaffolder->id('col'), 'field_id' => $fieldId];
                $columns = $block['columns'] ?? [];
                // Keep the trailing "Created" (sys_created_at) column last.
                $insertAt = count($columns);
                foreach ($columns as $i => $existing) {
                    if (($existing['field_id'] ?? null) === 'sys_created_at') {
                        $insertAt = $i;
                        break;
                    }
                }
                array_splice($columns, $insertAt, 0, [$column]);
                $block['columns'] = $columns;
            }

            if ($formToo
                && $type === 'form'
                && ($block['object_id'] ?? null) === $objectId
                && ($block['mode'] ?? null) === 'create'
            ) {
                $block['fields'][] = ['field_id' => $fieldId];
                if (isset($block['on_submit']) && is_array($block['on_submit'])) {
                    foreach ($block['on_submit'] as &$action) {
                        if (($action['type'] ?? null) === 'create_record' && ($action['object_id'] ?? null) === $objectId) {
                            $action['values'][$slug] = '{{form.'.$slug.'}}';
                        }
                    }
                    unset($action);
                }
            }

            if (isset($block['blocks']) && is_array($block['blocks'])) {
                $this->injectFieldIntoBlocks($block['blocks'], $objectId, $fieldId, $slug, $formToo);
            }
        }
        unset($block);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeManifest(App $app): array
    {
        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new \RuntimeException("App {$app->slug} has no active manifest.");
        }
        $manifest['objects'] ??= [];
        $manifest['pages'] ??= [];

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function findObjectIndex(array $manifest, string $objectSlug): int
    {
        foreach ($manifest['objects'] as $i => $object) {
            if (($object['slug'] ?? null) === $objectSlug) {
                return $i;
            }
        }

        $available = implode(', ', array_column($manifest['objects'], 'slug')) ?: '(none)';
        throw new \InvalidArgumentException("No object with slug \"{$objectSlug}\" exists in this app. Available objects: {$available}.");
    }

    /**
     * Slugify to ^[a-z][a-z0-9_]*$, keeping it unique within $taken.
     *
     * @param  array<int, string>  $taken
     */
    private function uniqueSlug(string $raw, array $taken, string $fallback): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($raw)), '_');
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = $slug === '' ? $fallback : 'f_'.$slug;
        }
        $slug = (string) Str::limit($slug, 50, '');

        $base = $slug;
        $n = 2;
        while (in_array($slug, $taken, true)) {
            $slug = (string) Str::limit($base, 47, '').'_'.$n++;
        }

        return $slug;
    }
}
