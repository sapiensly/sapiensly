<?php

namespace App\Services\Connected;

use Illuminate\Support\Str;

/**
 * Infers a connected object's fields + field_map from REAL sample rows — the
 * mechanical work that used to burn a slow model's whole turn (composing a
 * 20-field propose_change is minutes of generation; deriving it from the rows
 * is microseconds of code). Everything here is derived from the data: slugs
 * from keys (ascii/snake), types from values (boolean / number / date /
 * datetime / string), nested one-level scalars as dot paths, the external id
 * from an explicit path or an `id`-ish key. The model only says WHICH tool to
 * read — naming/labels can be refined later with a cheap patch.
 */
class ConnectedObjectModeler
{
    /** Rows examined when inferring types (union of keys across them). */
    private const SAMPLE_LIMIT = 25;

    /**
     * @param  list<array<string, mixed>>  $rows  raw external rows
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     field_map: list<array{field_id: string, external_path: string}>,
     *     id_path: ?string,
     * }
     */
    public function model(array $rows, ?string $idPath = null): array
    {
        $rows = array_values(array_filter(array_slice($rows, 0, self::SAMPLE_LIMIT), 'is_array'));

        // Collect candidate scalar paths (top-level + one nested level) with
        // their observed values across the sample.
        $values = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_array($value) && ! array_is_list($value)) {
                    foreach ($value as $childKey => $childValue) {
                        if (! is_array($childValue)) {
                            $values["{$key}.{$childKey}"][] = $childValue;
                        }
                    }

                    continue;
                }
                if (! is_array($value)) {
                    $values[$key][] = $value;
                }
            }
        }

        $idPath = $this->resolveIdPath($idPath, array_keys($values));

        $fields = [];
        $fieldMap = [];
        $takenSlugs = [];
        foreach ($values as $path => $observed) {
            if ($path === $idPath) {
                continue;
            }

            $slug = $this->uniqueSlug($this->slugForPath((string) $path), $takenSlugs);
            $takenSlugs[] = $slug;
            $fieldId = 'fld_'.strtolower((string) Str::ulid());

            $fields[] = [
                'id' => $fieldId,
                'slug' => $slug,
                'name' => Str::headline(Str::afterLast((string) $path, '.')),
                'type' => $this->inferType($observed),
            ];
            $fieldMap[] = ['field_id' => $fieldId, 'external_path' => (string) $path];
        }

        return ['fields' => $fields, 'field_map' => $fieldMap, 'id_path' => $idPath];
    }

    /**
     * @param  list<string>  $paths
     */
    private function resolveIdPath(?string $explicit, array $paths): ?string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return trim($explicit);
        }
        if (in_array('id', $paths, true)) {
            return 'id';
        }
        foreach ($paths as $path) {
            if (preg_match('/(^|[._])id$/', $path) === 1) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $observed
     */
    private function inferType(array $observed): string
    {
        $nonNull = array_values(array_filter($observed, fn ($v) => $v !== null && $v !== ''));
        if ($nonNull === []) {
            return 'string';
        }

        if ($nonNull === array_filter($nonNull, 'is_bool')) {
            return 'boolean';
        }
        // Numeric VALUES only — numeric strings stay strings (a "00123" folio
        // must not become a number and lose its leading zeros).
        if ($nonNull === array_filter($nonNull, fn ($v) => is_int($v) || is_float($v))) {
            return 'number';
        }

        $strings = array_filter($nonNull, 'is_string');
        if (count($strings) === count($nonNull)) {
            if ($nonNull === array_filter($nonNull, fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1)) {
                return 'date';
            }
            if ($nonNull === array_filter($nonNull, fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v) === 1)) {
                return 'datetime';
            }
        }

        return 'string';
    }

    private function slugForPath(string $path): string
    {
        $slug = strtolower(trim((string) preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            strtolower(Str::ascii(str_replace('.', '_', $path))),
        ), '_'));

        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'f_'.($slug === '' ? 'field' : $slug);
        }

        return $slug;
    }

    /**
     * @param  list<string>  $taken
     */
    private function uniqueSlug(string $slug, array $taken): string
    {
        $candidate = $slug;
        $n = 2;
        while (in_array($candidate, $taken, true)) {
            $candidate = $slug.'_'.$n++;
        }

        return $candidate;
    }
}
