<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Enriches a record set with values for derived field types (formula, lookup,
 * rollup) after the base RecordQueryService has loaded the rows.
 *
 * Conventions assumed by this resolver:
 *  - relation field stores the related record id in `data[<slug>]`
 *    (many_to_one) or an array of ids (many_to_many).
 *  - rollup over one_to_many uses the relation's `inverse_field_id` to look
 *    up children whose `data[<inverse_slug>]` points back at the parent id.
 */
class DerivedFieldsResolver
{
    /**
     * How many relation hops a derived field may chain through (a lookup of a
     * lookup of a lookup …). Bounds multi-hop resolution and stops a cyclic
     * lookup/rollup graph from recursing forever.
     */
    private const MAX_DEPTH = 5;

    public function __construct(private SafeExpressionEvaluator $safe) {}

    /**
     * @param  array<string, mixed>  $object  ObjectDef from the manifest
     * @param  array<string, mixed>  $manifest
     * @param  Collection<int, Record>  $records
     * @param  int  $depth  current relation-hop depth (multi-hop guard)
     */
    public function enrich(App $app, array $object, Collection $records, array $manifest, int $depth = 0): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        $derived = array_values(array_filter(
            $object['fields'],
            fn (array $f) => in_array($f['type'], ['formula', 'lookup', 'rollup'], true),
        ));

        if ($derived === [] || $records->isEmpty()) {
            return;
        }

        foreach ($derived as $field) {
            try {
                match ($field['type']) {
                    'lookup' => $this->resolveLookup($app, $object, $field, $records, $manifest, $depth),
                    'rollup' => $this->resolveRollup($app, $object, $field, $records, $manifest, $depth),
                    'formula' => $this->resolveFormula($object, $field, $records),
                };
            } catch (\Throwable $e) {
                Log::warning('Derived field resolution failed', [
                    'field_slug' => $field['slug'],
                    'type' => $field['type'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $field
     * @param  Collection<int, Record>  $records
     * @param  array<string, mixed>  $manifest
     */
    private function resolveLookup(App $app, array $object, array $field, Collection $records, array $manifest, int $depth = 0): void
    {
        $viaRelation = $this->findField($object, $field['via_relation_field_id']);
        if ($viaRelation === null || $viaRelation['type'] !== 'relation') {
            return;
        }

        $targetObject = $this->findObjectById($manifest, $viaRelation['target_object_id']);
        if ($targetObject === null) {
            return;
        }

        $targetField = $this->findField($targetObject, $field['target_field_id']);
        if ($targetField === null) {
            return;
        }

        // Gather the foreign ids stored on each parent row.
        $ids = [];
        foreach ($records as $record) {
            $foreign = $record->data[$viaRelation['slug']] ?? null;
            if (is_string($foreign) && $foreign !== '') {
                $ids[$foreign] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        $relatedRecords = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $targetObject['id'])
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'data']);

        // Multi-hop: when the looked-up field is itself derived (a lookup/formula/
        // rollup), resolve it on the related records first so the value is present
        // before we read it — this is what lets a lookup chain across relations
        // (e.g. line → product → category name).
        if (in_array($targetField['type'], ['formula', 'lookup', 'rollup'], true)) {
            $this->enrich($app, $targetObject, $relatedRecords, $manifest, $depth + 1);
        }

        $related = $relatedRecords->keyBy('id');

        foreach ($records as $record) {
            $foreign = $record->data[$viaRelation['slug']] ?? null;
            $resolved = is_string($foreign) ? ($related[$foreign]?->data[$targetField['slug']] ?? null) : null;
            $record->setAttribute('data', array_merge($record->data ?? [], [$field['slug'] => $resolved]));
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $field
     * @param  Collection<int, Record>  $records
     * @param  array<string, mixed>  $manifest
     */
    private function resolveRollup(App $app, array $object, array $field, Collection $records, array $manifest, int $depth = 0): void
    {
        $viaRelation = $this->findField($object, $field['via_relation_field_id']);
        if ($viaRelation === null || $viaRelation['type'] !== 'relation') {
            return;
        }

        $targetObject = $this->findObjectById($manifest, $viaRelation['target_object_id']);
        if ($targetObject === null) {
            return;
        }

        // For one_to_many we need the inverse field on the child object —
        // the field that points back at the parent.
        $inverseFieldId = $viaRelation['inverse_field_id'] ?? null;
        $inverseField = $inverseFieldId ? $this->findField($targetObject, $inverseFieldId) : null;
        if ($inverseField === null) {
            // Without inverse we can't query the children efficiently.
            // Default to empty results.
            foreach ($records as $record) {
                $record->setAttribute('data', array_merge($record->data ?? [], [$field['slug'] => null]));
            }

            return;
        }

        $parentIds = $records->pluck('id')->all();
        if ($parentIds === []) {
            return;
        }

        $aggregator = $field['aggregator'];
        $targetSlug = null;
        $targetField = null;
        if (isset($field['target_field_id'])) {
            $targetField = $this->findField($targetObject, $field['target_field_id']);
            $targetSlug = $targetField['slug'] ?? null;
        }

        // Fetch ALL children that reference any of the parents in one go,
        // then group locally — avoids N+1 over the parent list.
        $children = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $targetObject['id'])
            ->whereRaw('data->>? = ANY(?)', [$inverseField['slug'], '{'.implode(',', array_map(fn ($id) => '"'.$id.'"', $parentIds)).'}'])
            ->get(['id', 'data']);

        // When aggregating a derived child field (e.g. sum a per-line formula),
        // resolve the children's derived values before folding them.
        if ($targetField !== null && in_array($targetField['type'], ['formula', 'lookup', 'rollup'], true)) {
            $this->enrich($app, $targetObject, $children, $manifest, $depth + 1);
        }

        $childrenByParent = $children->groupBy(fn (Record $r) => $r->data[$inverseField['slug']] ?? null);

        foreach ($records as $record) {
            $children = $childrenByParent[$record->id] ?? collect();
            $value = $this->aggregate($children, $aggregator, $targetSlug);
            $record->setAttribute('data', array_merge($record->data ?? [], [$field['slug'] => $value]));
        }
    }

    /**
     * @param  Collection<int, Record>  $children
     */
    private function aggregate(Collection $children, string $aggregator, ?string $targetSlug): mixed
    {
        return match ($aggregator) {
            'count' => $children->count(),
            'count_distinct' => $targetSlug
                ? $children->pluck("data.{$targetSlug}")->unique()->count()
                : $children->count(),
            'sum' => $targetSlug ? $children->sum(fn (Record $c) => (float) ($c->data[$targetSlug] ?? 0)) : 0,
            'avg' => $targetSlug && $children->isNotEmpty()
                ? $children->avg(fn (Record $c) => (float) ($c->data[$targetSlug] ?? 0))
                : null,
            'min' => $targetSlug ? $children->min(fn (Record $c) => $c->data[$targetSlug] ?? null) : null,'max' => $targetSlug ? $children->max(fn (Record $c) => $c->data[$targetSlug] ?? null) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $field
     * @param  Collection<int, Record>  $records
     */
    private function resolveFormula(array $object, array $field, Collection $records): void
    {
        // Bind every declared field slug so a referenced-but-unset field reads
        // as null instead of tripping an "undefined variable" error.
        $base = array_fill_keys(array_column($object['fields'], 'slug'), null);

        foreach ($records as $record) {
            $rowData = array_merge($base, $record->data ?? []);
            $value = $this->evaluateFormula((string) $field['expression'], $rowData);
            $record->setAttribute('data', array_merge($record->data ?? [], [$field['slug'] => $value]));
        }
    }

    /**
     * Evaluate a formula expression against a single row. Each `{{ … }}` token
     * is a real, sandboxed expression over this row's fields (referenced by
     * slug as bare variables) — so `{{monto * 1.16}}`, `{{upper(apellido)}}`
     * and `{{activo ? "Sí" : "No"}}` all evaluate.
     *
     * When the whole formula is a single token, the typed value is returned
     * (a number stays a number for return_type=number). When it's a template
     * with surrounding text or several tokens (`{{nombre}} {{upper(apellido)}}`),
     * each token is interpolated into the literal text as a string.
     *
     * @param  array<string, mixed>  $rowData
     */
    private function evaluateFormula(string $expression, array $rowData): mixed
    {
        $trimmed = trim($expression);

        if (str_starts_with($trimmed, '{{') && str_ends_with($trimmed, '}}') && substr_count($trimmed, '{{') === 1) {
            return $this->evaluateToken(substr($trimmed, 2, -2), $rowData);
        }

        return preg_replace_callback(
            '/\{\{\s*([^}]+?)\s*\}\}/',
            function (array $m) use ($rowData): string {
                $value = $this->evaluateToken($m[1], $rowData);

                return is_scalar($value) ? (string) $value : '';
            },
            $trimmed,
        );
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private function evaluateToken(string $inner, array $rowData): mixed
    {
        try {
            return $this->safe->evaluate(trim($inner), $rowData);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function findField(array $object, string $fieldId): ?array
    {
        foreach ($object['fields'] as $field) {
            if ($field['id'] === $fieldId) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findObjectById(array $manifest, string $objectId): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if ($object['id'] === $objectId) {
                return $object;
            }
        }

        return null;
    }
}
