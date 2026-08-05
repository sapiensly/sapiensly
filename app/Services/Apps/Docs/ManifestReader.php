<?php

namespace App\Services\Apps\Docs;

/**
 * Read-only navigation over a manifest, for the two document writers.
 *
 * A manifest is a graph flattened into JSON: blocks point at objects by id,
 * columns at fields by id, relations at their inverse by id. Both writers spend
 * most of their work resolving those ids back into names, so the resolution
 * lives here once — and the walk over nested blocks lives here too, because a
 * scan that only looks at the top level misses everything inside a tab or a row
 * container, which is a mistake this codebase has made twice.
 */
final class ManifestReader
{
    /** @var array<string, array<string, mixed>> */
    private array $objectsById = [];

    /** @var array<string, array<string, mixed>> */
    private array $fieldsById = [];

    /** @var array<string, string> field id => object id */
    private array $objectIdByFieldId = [];

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(public readonly array $manifest)
    {
        foreach ($this->objects() as $object) {
            $objectId = (string) ($object['id'] ?? '');
            $this->objectsById[$objectId] = $object;

            foreach ($object['fields'] ?? [] as $field) {
                $fieldId = (string) ($field['id'] ?? '');
                $this->fieldsById[$fieldId] = $field;
                $this->objectIdByFieldId[$fieldId] = $objectId;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function objects(): array
    {
        return array_values(array_filter(
            $this->manifest['objects'] ?? [],
            is_array(...),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pages(): array
    {
        return array_values(array_filter(
            $this->manifest['pages'] ?? [],
            is_array(...),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workflows(): array
    {
        return array_values(array_filter(
            $this->manifest['workflows'] ?? [],
            is_array(...),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function object(?string $id): ?array
    {
        return $this->objectsById[(string) $id] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(?string $id): ?array
    {
        return $this->fieldsById[(string) $id] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function objectOfField(?string $id): ?array
    {
        return $this->object($this->objectIdByFieldId[(string) $id] ?? null);
    }

    public function objectName(?string $id, string $fallback = ''): string
    {
        return (string) ($this->object($id)['name'] ?? $fallback);
    }

    /**
     * A field's human name, including the system columns a table may carry,
     * which are real columns with no field definition behind them.
     */
    public function fieldName(?string $id, string $fallback = ''): string
    {
        $name = $this->field($id)['name'] ?? null;
        if (is_string($name)) {
            return $name;
        }

        return match ((string) $id) {
            'sys_created_at' => 'Created',
            'sys_updated_at' => 'Updated',
            'sys_id' => 'Id',
            default => $fallback !== '' ? $fallback : (string) $id,
        };
    }

    /**
     * Every block on a page, nested ones included, depth-first in reading order.
     *
     * Blocks nest four different ways — `blocks` on a container or a modal,
     * `tabs[].blocks`, `left`/`right` on a split view — so the walk asks the
     * shape rather than assuming one key.
     *
     * @param  array<string, mixed>  $page
     * @return list<array{block: array<string, mixed>, depth: int, parent: string|null, parent_label: string|null}>
     */
    public function blocksOf(array $page): array
    {
        $out = [];
        $this->walk($page['blocks'] ?? [], 0, null, null, $out);

        return $out;
    }

    /**
     * Every block on every page.
     *
     * @return list<array{page: array<string, mixed>, block: array<string, mixed>, depth: int, parent: string|null, parent_label: string|null}>
     */
    public function allBlocks(): array
    {
        $out = [];
        foreach ($this->pages() as $page) {
            foreach ($this->blocksOf($page) as $entry) {
                $out[] = ['page' => $page] + $entry;
            }
        }

        return $out;
    }

    /**
     * The parent's LABEL travels down with its type, because a form inside a
     * modal has no name of its own: the only thing that says whether it is the
     * one that adds a record or the one that edits it is the modal's title.
     *
     * @param  mixed  $blocks
     * @param  list<array{block: array<string, mixed>, depth: int, parent: string|null, parent_label: string|null}>  $out
     */
    private function walk($blocks, int $depth, ?string $parent, ?string $parentLabel, array &$out): void
    {
        if (! is_array($blocks)) {
            return;
        }

        foreach ($blocks as $block) {
            if (! is_array($block) || ! isset($block['type'])) {
                continue;
            }

            $out[] = ['block' => $block, 'depth' => $depth, 'parent' => $parent, 'parent_label' => $parentLabel];
            $type = (string) $block['type'];
            $label = trim((string) ($block['title'] ?? $block['label'] ?? '')) ?: null;

            foreach (['blocks', 'left', 'right'] as $key) {
                $this->walk($block[$key] ?? null, $depth + 1, $type, $label, $out);
            }

            foreach ($block['tabs'] ?? [] as $tab) {
                if (is_array($tab)) {
                    $this->walk($tab['blocks'] ?? null, $depth + 1, $type, $label, $out);
                }
            }
        }
    }

    /**
     * The object a block reads from, whichever way it names it.
     */
    public function objectIdOf(array $block): ?string
    {
        $candidates = [
            $block['object_id'] ?? null,
            $block['data_source']['object_id'] ?? null,
            $block['query']['object_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A block's COLUMN DEFINITIONS, or none.
     *
     * `columns` is two different things in the manifest schema: a list of
     * column definitions on `table`, `related_list` and `data_grid`, and a
     * plain COUNT on the grid blocks (`metric_grid`, `card_grid`,
     * `feature_grid`, `testimonials`, `pricing`). Walking every block and
     * iterating `columns` therefore hits an integer the moment an app has a
     * dashboard — which is nearly every app, and is why both documents 500'd
     * rather than rendered. BlockVisibilityFilter and ManifestIdFiller each
     * solved this privately; this is the shared answer for the writers.
     *
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    public static function columnsOf(array $block): array
    {
        $columns = $block['columns'] ?? null;

        return is_array($columns)
            ? array_values(array_filter($columns, 'is_array'))
            : [];
    }

    /**
     * How many columns a block declares, whichever of the two shapes it uses.
     */
    public static function columnCountOf(array $block): ?int
    {
        $columns = $block['columns'] ?? null;

        return match (true) {
            is_array($columns) => count($columns),
            is_int($columns) => $columns,
            default => null,
        };
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->manifest['settings'][$key] ?? $default;
    }

    public function locale(): string
    {
        return (string) $this->setting('default_locale', 'en');
    }
}
