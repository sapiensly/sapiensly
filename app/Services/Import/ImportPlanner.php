<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

/**
 * Turns a parsed sheet into a reviewable {@see ImportPlan}, in one of two modes:
 *
 *  - CREATE: the file defines a new object. Every column becomes a field, typed
 *    by the profiler. This is the "my business is now inside the app in two
 *    minutes" path.
 *  - EXISTING: the file feeds an object that already exists. Columns are matched
 *    to fields by name, and anything unmatched is reported as skipped rather
 *    than invented — adding fields to a live object is a schema change the user
 *    should make deliberately, not a side effect of an upload.
 *
 * The planner never writes anything.
 */
class ImportPlanner
{
    public function __construct(
        private readonly ColumnProfiler $profiler = new ColumnProfiler,
    ) {}

    /**
     * Plan a brand-new object built from the file itself.
     */
    public function planNewObject(SheetData $sheet, string $objectName): ImportPlan
    {
        $warnings = $this->sheetWarnings($sheet);

        $fields = [];
        $mappings = [];
        $usedSlugs = [];

        foreach ($sheet->headers as $header) {
            $profile = $this->profiler->profile($header, $sheet->column($header));
            $slug = $this->uniqueSlug($header, $usedSlugs);
            $usedSlugs[$slug] = true;

            $fieldId = $this->mintId('fld');
            $fields[] = $profile->toField($fieldId, $slug);
            $mappings[] = new ColumnMapping($header, $slug, $fieldId, $profile->type, $profile);

            foreach ($profile->notes as $note) {
                $warnings[] = "«{$header}»: {$note}";
            }
        }

        $objectSlug = $this->uniqueSlug($objectName, []);
        $object = [
            'id' => $this->mintId('obj'),
            'slug' => $objectSlug === '' ? 'imported' : $objectSlug,
            'name' => $objectName,
            'fields' => $fields,
        ];

        // A display field is what every table, relation picker and record title
        // falls back to. The first text column is nearly always the right one.
        $display = $this->firstTextField($fields);
        if ($display !== null) {
            $object['primary_display_field_id'] = $display;
        }

        return new ImportPlan(
            mode: ImportPlan::MODE_CREATE,
            object: $object,
            mappings: $mappings,
            totalRows: $sheet->totalRows,
            truncated: $sheet->truncated,
            warnings: $warnings,
            sample: $sheet->sampleRows(),
        );
    }

    /**
     * Plan an import into an object that already exists.
     *
     * @param  array<string, mixed>  $object  the manifest object definition
     * @param  array<string, string>  $overrides  header => field slug, from a human correcting the match
     * @param  string|null  $upsertKeyHeader  the column identifying an existing record
     */
    public function planExistingObject(
        SheetData $sheet,
        array $object,
        array $overrides = [],
        ?string $upsertKeyHeader = null,
    ): ImportPlan {
        $warnings = $this->sheetWarnings($sheet);

        // Derived fields are computed at query time — writing to one is
        // rejected downstream, so they are not offered as targets at all.
        $writable = array_values(array_filter(
            $object['fields'] ?? [],
            fn (array $f): bool => ! in_array($f['type'] ?? '', ['formula', 'lookup', 'rollup'], true),
        ));

        $bySlug = [];
        $byNormalizedName = [];
        foreach ($writable as $field) {
            $bySlug[$field['slug']] = $field;
            $byNormalizedName[$this->normalize($field['name'] ?? $field['slug'])] = $field;
            $byNormalizedName[$this->normalize($field['slug'])] = $field;
        }

        $mappings = [];
        $claimed = [];

        foreach ($sheet->headers as $header) {
            $profile = $this->profiler->profile($header, $sheet->column($header));

            $field = isset($overrides[$header])
                ? ($bySlug[$overrides[$header]] ?? null)
                : ($byNormalizedName[$this->normalize($header)] ?? null);

            if ($field === null) {
                $mappings[] = ColumnMapping::skipped(
                    $profile,
                    "No field in «{$object['name']}» matches this column.",
                );

                continue;
            }

            // Two columns cannot feed one field; the second would silently
            // overwrite the first, row by row.
            if (isset($claimed[$field['slug']])) {
                $mappings[] = ColumnMapping::skipped(
                    $profile,
                    "«{$claimed[$field['slug']]}» is already mapped to «{$field['name']}».",
                );

                continue;
            }
            $claimed[$field['slug']] = $header;

            $mappings[] = new ColumnMapping($header, $field['slug'], $field['id'], $field['type'], $profile);

            foreach ($profile->notes as $note) {
                $warnings[] = "«{$header}»: {$note}";
            }
        }

        $mappedCount = count(array_filter($mappings, fn (ColumnMapping $m): bool => $m->fieldSlug !== null));
        if ($mappedCount === 0) {
            $warnings[] = 'No column in this file matches a field in the target object — nothing would be imported.';
        }

        $upsertKey = null;
        if ($upsertKeyHeader !== null) {
            $match = array_values(array_filter(
                $mappings,
                fn (ColumnMapping $m): bool => $m->header === $upsertKeyHeader && $m->fieldSlug !== null,
            ));
            if ($match === []) {
                $warnings[] = "«{$upsertKeyHeader}» cannot be the matching column: it is not mapped to a field.";
            } else {
                $upsertKey = $match[0]->fieldSlug;
            }
        }

        return new ImportPlan(
            mode: ImportPlan::MODE_EXISTING,
            object: [
                'id' => $object['id'],
                'slug' => $object['slug'],
                'name' => $object['name'],
                'fields' => array_map(
                    fn (array $f): array => ['id' => $f['id'], 'slug' => $f['slug'], 'name' => $f['name'], 'type' => $f['type']],
                    $writable,
                ),
            ],
            mappings: $mappings,
            totalRows: $sheet->totalRows,
            truncated: $sheet->truncated,
            upsertKey: $upsertKey,
            warnings: $warnings,
            sample: $sheet->sampleRows(),
        );
    }

    /**
     * @return list<string>
     */
    private function sheetWarnings(SheetData $sheet): array
    {
        $warnings = [];

        if ($sheet->headers === []) {
            $warnings[] = 'The file has no header row — the first row must name the columns.';
        }
        if ($sheet->truncated) {
            $warnings[] = 'The file has '.$sheet->totalRows.' rows; only the first '
                .SpreadsheetReader::MAX_ROWS.' will be imported. Split it to import the rest.';
        }

        return $warnings;
    }

    /**
     * A manifest slug: snake_case, starting with a letter, accents folded.
     *
     * @param  array<string, true>  $used
     */
    private function uniqueSlug(string $header, array $used): string
    {
        $base = Str::snake(Str::ascii($header));
        $base = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $base));
        $base = trim((string) preg_replace('/_+/', '_', $base), '_');

        // The schema requires a leading letter, so a column named "2024" or
        // "% margen" needs a prefix rather than a rejection at save time.
        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'col_'.$base;
            $base = rtrim($base, '_');
        }

        $candidate = $base;
        $n = 2;
        while (isset($used[$candidate])) {
            $candidate = $base.'_'.$n;
            $n++;
        }

        return $candidate;
    }

    /**
     * Fold a header and a field name to the same shape so «Correo Electrónico»
     * matches `correo_electronico`.
     */
    private function normalize(string $value): string
    {
        $value = strtolower(Str::ascii($value));

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function firstTextField(array $fields): ?string
    {
        foreach ($fields as $field) {
            if (in_array($field['type'], ['string', 'long_text'], true)) {
                return $field['id'];
            }
        }

        return $fields[0]['id'] ?? null;
    }

    private function mintId(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }
}
