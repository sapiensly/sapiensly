<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;

/**
 * Builds the "big picture" digest of an app's data model: the objects, their
 * fields (with type + derived/relation metadata), live per-object record counts,
 * the relation graph (edges between objects), and which workflows hook each
 * object. This is the digested data dictionary — distinct from the raw manifest
 * (the authored design) and the meta-schema (how to author a manifest).
 *
 * One source of truth for every "what does this app's data look like" consumer:
 * the builder schema view, the MCP describe_app_data tool, and the in-app agent's
 * describe_capabilities tool all read from here, so they never drift.
 */
class AppDataOverview
{
    /** Field types whose value is computed (read-only), not stored/edited directly. */
    private const DERIVED_TYPES = ['formula', 'lookup', 'rollup'];

    /**
     * Full digest for the builder schema view: keeps the complete manifest object
     * arrays (annotated with system fields) plus counts, the relation graph and
     * workflows-by-object. `record_counts` is retained as a flat map for callers
     * that index by object id.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array{objects: list<array<string, mixed>>, record_counts: array<string, int>, relations: list<array<string, mixed>>, workflows_by_object: array<string, list<array<string, mixed>>>}|null
     */
    public function full(App $app, ?array $manifest): ?array
    {
        if ($manifest === null) {
            return null;
        }

        $objects = $manifest['objects'] ?? [];
        $counts = $this->recordCounts($app);

        return [
            'objects' => array_map(
                fn (array $o) => $this->annotateObjectWithSystemFields($o),
                $objects,
            ),
            'record_counts' => $counts,
            'relations' => $this->relations($objects),
            'workflows_by_object' => $this->workflowsByObject($manifest),
        ];
    }

    /**
     * Lean digest for agents (MCP + in-app): each object trimmed to id, slug,
     * name, description, source and record_count, with fields projected to the
     * essentials a consumer needs to query (id/slug/name/type + derived flag and,
     * for relations, target_object_id + cardinality). Plus the relation graph and
     * workflows-by-object.
     *
     * `$objectIdFilter`, when given, restricts the digest to those object ids
     * (the agent's read grant) so it never reveals objects it cannot query.
     *
     * @param  array<string, mixed>|null  $manifest
     * @param  list<string>|null  $objectIdFilter
     * @return array{objects: list<array<string, mixed>>, relations: list<array<string, mixed>>, workflows_by_object: array<string, list<array<string, mixed>>>}
     */
    public function compact(App $app, ?array $manifest, ?array $objectIdFilter = null): array
    {
        if ($manifest === null) {
            return ['objects' => [], 'relations' => [], 'workflows_by_object' => []];
        }

        $allObjects = $manifest['objects'] ?? [];
        $visible = $objectIdFilter === null
            ? $allObjects
            : array_values(array_filter(
                $allObjects,
                fn (array $o) => in_array($o['id'] ?? null, $objectIdFilter, true),
            ));

        $counts = $this->recordCounts($app);

        $objects = array_map(function (array $o) use ($counts) {
            $source = $o['source']['type'] ?? 'internal';

            return [
                'id' => $o['id'] ?? null,
                'slug' => $o['slug'] ?? null,
                'name' => $o['name'] ?? null,
                'description' => $o['description'] ?? null,
                'source' => $source,
                // Connected objects have no internal record store, so a count is
                // not meaningful — report null rather than a misleading 0.
                'record_count' => $source === 'connected' ? null : ($counts[$o['id'] ?? ''] ?? 0),
                'fields' => array_map(fn (array $f) => $this->compactField($f), $o['fields'] ?? []),
                'system_fields' => $this->systemFields(),
            ];
        }, $visible);

        return [
            'objects' => $objects,
            'relations' => $this->relations($visible),
            'workflows_by_object' => $this->workflowsByObject($manifest),
        ];
    }

    /**
     * One grouped COUNT(*) for the whole app, keyed by object id — beats N
     * per-object COUNT round-trips. Tenant/RLS scoping applies via the Record
     * model's connection.
     *
     * @return array<string, int>
     */
    private function recordCounts(App $app): array
    {
        if (($app->id ?? null) === null) {
            return [];
        }

        return Record::query()
            ->where('app_id', $app->id)
            ->selectRaw('object_definition_id, count(*) as c')
            ->groupBy('object_definition_id')
            ->pluck('c', 'object_definition_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Derive the relation graph from relation-typed fields. Each relation field
     * carries target_object_id + cardinality; the many_to_one side is the owning
     * belongs-to (the FK stored in the child's JSONB), one_to_many its inverse
     * has-many. Emitting both sides gives a consumer the full edge set.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function relations(array $objects): array
    {
        $edges = [];
        foreach ($objects as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) !== 'relation' || ! isset($field['target_object_id'])) {
                    continue;
                }
                $cardinality = $field['cardinality'] ?? 'many_to_one';
                $edges[] = [
                    'field_id' => $field['id'] ?? null,
                    'name' => $field['name'] ?? null,
                    'from_object_id' => $object['id'] ?? null,
                    'from_field_slug' => $field['slug'] ?? null,
                    'to_object_id' => $field['target_object_id'],
                    'cardinality' => $cardinality,
                    'kind' => $cardinality === 'one_to_many' ? 'has_many' : 'belongs_to',
                ];
            }
        }

        return $edges;
    }

    /**
     * Bucket workflows by the object their trigger hooks into, so each object can
     * show its automation neighbours at a glance.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, list<array<string, mixed>>>
     */
    private function workflowsByObject(array $manifest): array
    {
        $byObject = [];
        foreach ($manifest['workflows'] ?? [] as $wf) {
            $objectId = $wf['trigger']['object_id'] ?? null;
            if ($objectId === null) {
                continue;
            }
            $byObject[$objectId] ??= [];
            $byObject[$objectId][] = [
                'id' => $wf['id'],
                'name' => $wf['name'] ?? $wf['slug'],
                'trigger_type' => $wf['trigger']['type'] ?? null,
            ];
        }

        return $byObject;
    }

    /**
     * Project a manifest field to the essentials an agent needs to query it.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function compactField(array $field): array
    {
        $out = [
            'id' => $field['id'] ?? null,
            'slug' => $field['slug'] ?? null,
            'name' => $field['name'] ?? null,
            'type' => $field['type'] ?? null,
            'derived' => in_array($field['type'] ?? null, self::DERIVED_TYPES, true),
        ];

        if (($field['type'] ?? null) === 'relation') {
            $out['target_object_id'] = $field['target_object_id'] ?? null;
            $out['cardinality'] = $field['cardinality'] ?? null;
        }

        return $out;
    }

    /**
     * Append the virtual system fields (sys_created_at, sys_updated_at) so a
     * consumer can list/sort/filter them alongside user-declared fields.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function annotateObjectWithSystemFields(array $object): array
    {
        $object['system_fields'] = $this->systemFields();

        return $object;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function systemFields(): array
    {
        return [
            RecordQueryService::systemField('sys_created_at'),
            RecordQueryService::systemField('sys_updated_at'),
        ];
    }
}
