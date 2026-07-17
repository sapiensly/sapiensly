<?php

namespace App\Services\Manifest;

use App\Models\App;
use App\Models\Record;

/**
 * A full-app health check that spans both layers the other tools each cover only
 * half of: the MANIFEST (validated by ManifestValidator — schema + resolved
 * references) AND the live DATA (records honouring that manifest), which nothing
 * else audits. It runs the validator against the app's *applied* version, then
 * scans records for the integrity a static manifest check cannot see:
 *
 *  - dangling relation FKs: a relation value pointing at a record that no longer
 *    exists in the target object;
 *  - invalid select values: a single/multi-select value that is not one of the
 *    field's current options;
 *  - orphaned records: rows whose object_definition_id is no longer in the
 *    manifest (its object was deleted, the data was not).
 *
 * Read-only and tenant-scoped: Record runs on the tenant connection, so RLS
 * confines every count to the caller's organization.
 */
class AppAuditService
{
    /** Per-check cap on the example rows returned, so a broken app can't return a huge payload. */
    private const SAMPLE_LIMIT = 5;

    public function __construct(
        private ManifestValidator $validator,
        private AppManifestService $manifests,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(App $app): array
    {
        $manifest = $this->manifests->getActiveManifest($app);

        if ($manifest === null) {
            return [
                'app' => $this->appHeader($app, null),
                'manifest' => [
                    'valid' => false,
                    'errors' => [['path' => '', 'message' => 'App has no active manifest to audit.', 'code' => 'no_manifest']],
                    'warnings' => [],
                ],
                'data' => null,
                'summary' => ['ok' => false, 'manifest_errors' => 1, 'manifest_warnings' => 0, 'data_issues' => 0],
            ];
        }

        $manifestResult = $this->validator->validate($manifest);
        $data = $this->auditData($app, $manifest);

        return [
            'app' => $this->appHeader($app, $manifest),
            'manifest' => [
                'valid' => $manifestResult->valid,
                'errors' => $manifestResult->errorsArray(),
                'warnings' => $manifestResult->warningsArray(),
            ],
            'data' => $data['report'],
            'summary' => [
                'ok' => $manifestResult->valid && $data['total_issues'] === 0,
                'manifest_errors' => count($manifestResult->errors),
                'manifest_warnings' => count($manifestResult->warnings),
                'data_issues' => $data['total_issues'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, mixed>
     */
    private function appHeader(App $app, ?array $manifest): array
    {
        return array_filter([
            'id' => $app->id,
            'slug' => $app->slug,
            'name' => $app->name,
            'current_version_id' => $app->current_version_id,
            'version_number' => $manifest['version'] ?? null,
            'object_count' => $manifest !== null ? count($manifest['objects'] ?? []) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Scan every object's records for FK, select and orphan integrity.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{total_issues: int, report: array<string, mixed>}
     */
    private function auditData(App $app, array $manifest): array
    {
        /** @var list<array<string, mixed>> $objects */
        $objects = $manifest['objects'] ?? [];
        $objectIds = array_values(array_filter(array_column($objects, 'id')));
        $slugById = [];
        foreach ($objects as $object) {
            $slugById[$object['id']] = $object['slug'] ?? $object['id'];
        }

        // Record-id set per object, so a relation value can be resolved with an
        // O(1) lookup instead of a query per record. Keys are the ids; the value
        // is irrelevant (array_flip turns the id list into a membership set).
        $idsByObject = [];
        foreach ($objectIds as $objectId) {
            $ids = Record::query()
                ->where('app_id', $app->id)
                ->where('object_definition_id', $objectId)
                ->pluck('id')
                ->all();
            $idsByObject[$objectId] = array_flip($ids);
        }

        $objectReports = [];
        $totalIssues = 0;

        foreach ($objects as $object) {
            [$relationChecks, $selectChecks] = $this->fieldCheckers($object['fields'] ?? []);
            $recordCount = count($idsByObject[$object['id']] ?? []);

            $dangling = ['count' => 0, 'samples' => []];
            $invalidSelect = ['count' => 0, 'samples' => []];

            if ($recordCount > 0 && ($relationChecks !== [] || $selectChecks !== [])) {
                foreach ($this->recordsOf($app, $object['id']) as $record) {
                    $rowData = is_array($record->data) ? $record->data : [];

                    foreach ($relationChecks as $check) {
                        foreach ($this->valuesFor($rowData, $check['slug'], $check['multi']) as $value) {
                            $targetSet = $idsByObject[$check['target']] ?? [];
                            if (! is_scalar($value) || ! isset($targetSet[$value])) {
                                $dangling['count']++;
                                $this->sample($dangling['samples'], [
                                    'record_id' => $record->id,
                                    'field' => $check['slug'],
                                    'target_object' => $slugById[$check['target']] ?? $check['target'],
                                    'bad_value' => is_scalar($value) ? $value : null,
                                ]);
                            }
                        }
                    }

                    foreach ($selectChecks as $check) {
                        foreach ($this->valuesFor($rowData, $check['slug'], $check['multi']) as $value) {
                            if (! isset($check['allowed'][(string) $value])) {
                                $invalidSelect['count']++;
                                $this->sample($invalidSelect['samples'], [
                                    'record_id' => $record->id,
                                    'field' => $check['slug'],
                                    'bad_value' => is_scalar($value) ? $value : null,
                                ]);
                            }
                        }
                    }
                }
            }

            $issues = $dangling['count'] + $invalidSelect['count'];
            $totalIssues += $issues;

            $objectReports[] = array_filter([
                'object' => $object['slug'] ?? $object['id'],
                'name' => $object['name'] ?? null,
                'record_count' => $recordCount,
                'ok' => $issues === 0,
                'dangling_relations' => $dangling['count'] > 0 ? $dangling : null,
                'invalid_select_values' => $invalidSelect['count'] > 0 ? $invalidSelect : null,
            ], fn ($v) => $v !== null);
        }

        $orphans = $this->orphanedRecords($app, $objectIds);
        $totalIssues += $orphans['total'];

        return [
            'total_issues' => $totalIssues,
            'report' => array_filter([
                'objects' => $objectReports,
                'orphaned_records' => $orphans['total'] > 0 ? $orphans : null,
            ], fn ($v) => $v !== null),
        ];
    }

    /**
     * Split an object's fields into the two data checks: relation fields that
     * STORE a foreign key (many_to_one scalar / many_to_many array — the inverse
     * one_to_many stores nothing) and option fields (single/multi-select).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array{0: list<array{slug: string, target: string, multi: bool}>, 1: list<array{slug: string, allowed: array<string, int>, multi: bool}>}
     */
    private function fieldCheckers(array $fields): array
    {
        $relationChecks = [];
        $selectChecks = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? '';

            if ($type === 'relation') {
                $cardinality = $field['cardinality'] ?? '';
                if (in_array($cardinality, ['many_to_one', 'many_to_many'], true) && isset($field['target_object_id'])) {
                    $relationChecks[] = [
                        'slug' => $field['slug'],
                        'target' => $field['target_object_id'],
                        'multi' => $cardinality === 'many_to_many',
                    ];
                }

                continue;
            }

            if (isset($field['options']) && is_array($field['options'])) {
                $allowed = array_flip(array_map(
                    static fn ($opt): string => (string) ($opt['value'] ?? ''),
                    $field['options'],
                ));
                $selectChecks[] = [
                    'slug' => $field['slug'],
                    'allowed' => $allowed,
                    'multi' => $type === 'multi_select',
                ];
            }
        }

        return [$relationChecks, $selectChecks];
    }

    /**
     * The present (non-empty) value(s) of a field on a record's data, always as a
     * list so scalar and array-valued fields share one loop.
     *
     * @param  array<string, mixed>  $rowData
     * @return list<mixed>
     */
    private function valuesFor(array $rowData, string $slug, bool $multi): array
    {
        $value = $rowData[$slug] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($multi) {
            return is_array($value) ? array_values($value) : [$value];
        }

        return [$value];
    }

    /**
     * Distinct object_definition_ids present in records but absent from the
     * manifest — rows left behind when their object was removed.
     *
     * @param  list<string>  $manifestObjectIds
     * @return array{total: int, by_object: list<array{object_definition_id: string, record_count: int}>}
     */
    private function orphanedRecords(App $app, array $manifestObjectIds): array
    {
        $present = Record::query()
            ->where('app_id', $app->id)
            ->distinct()
            ->pluck('object_definition_id')
            ->all();

        $byObject = [];
        $total = 0;
        foreach (array_diff($present, $manifestObjectIds) as $orphanId) {
            $count = Record::query()
                ->where('app_id', $app->id)
                ->where('object_definition_id', $orphanId)
                ->count();
            $byObject[] = ['object_definition_id' => $orphanId, 'record_count' => $count];
            $total += $count;
        }

        return ['total' => $total, 'by_object' => $byObject];
    }

    /**
     * A memory-bounded cursor over one object's records, projecting only what the
     * checks read.
     *
     * @return iterable<Record>
     */
    private function recordsOf(App $app, string $objectId): iterable
    {
        return Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $objectId)
            ->select(['id', 'data'])
            ->cursor();
    }

    /**
     * Append an example to a capped sample list (drops silently once full — the
     * count above it is exact regardless).
     *
     * @param  list<array<string, mixed>>  $samples
     * @param  array<string, mixed>  $sample
     */
    private function sample(array &$samples, array $sample): void
    {
        if (count($samples) < self::SAMPLE_LIMIT) {
            $samples[] = $sample;
        }
    }
}
