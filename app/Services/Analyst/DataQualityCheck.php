<?php

namespace App\Services\Analyst;

use App\Services\Records\FieldPaths;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The analyst's read of how much to TRUST the data before drawing conclusions:
 * stale sources and high-null columns become confidence flags, so a
 * recommendation is never quietly built on rotten data. Deterministic, over the
 * rows the recommender already sampled — no extra reads, no model.
 */
class DataQualityCheck
{
    /** A source untouched longer than this (days) reads as stale. */
    private const STALE_DAYS = 3;

    /** A measure/dimension emptier than this reads as unreliable. */
    private const NULL_RATE = 0.4;

    private const MAX_FLAGS = 4;

    /**
     * @param  array<string, array{object: array<string, mixed>, rows: list<array<string, mixed>>, facts: array<string, mixed>}>  $byObject
     * @return list<array{level: 'warn'|'info', text: string}>
     */
    public function run(array $byObject, bool $es): array
    {
        $flags = [];
        // The same generic column ("Total Tickets", "Key") lives on many sources;
        // without this the strip repeats "Total Tickets: 100% vacío" once per
        // source. One completeness flag per field NAME across the whole board.
        $seenEmpty = [];

        foreach ($byObject as $entry) {
            try {
                $this->freshness($entry, $es, $flags);
                $this->nulls($entry, $es, $flags, $seenEmpty);
            } catch (\Throwable) {
                continue; // one bad source never sinks the panel
            }
        }

        // Freshness (warn) before completeness (info); cap so the strip stays
        // scannable.
        usort($flags, fn (array $a, array $b): int => ($a['level'] === 'warn' ? 0 : 1) <=> ($b['level'] === 'warn' ? 0 : 1));

        return array_slice($flags, 0, self::MAX_FLAGS);
    }

    /**
     * @param  array{object: array<string, mixed>, facts: array<string, mixed>}  $entry
     * @param  list<array{level: string, text: string}>  $flags
     */
    private function freshness(array $entry, bool $es, array &$flags): void
    {
        // The last date the SOURCE carries — not the last date we chose to READ.
        // MaturationCheck drops trailing periods that have not resolved, and without
        // `source_latest` that trim would make the freshest feed in the system accuse
        // itself of being five days stale. Staleness is about the source; maturity is
        // about the window. Two different questions, and only one of them is answered
        // by the last row of the analysed set.
        $latest = $entry['facts']['source_latest']
            ?? collect($entry['facts']['trend'] ?? [])->pluck('span_to')->filter()->max();
        if ($latest === null) {
            return;
        }
        $days = (int) Carbon::parse((string) $latest)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
        if ($days <= self::STALE_DAYS) {
            return;
        }
        $name = (string) ($entry['object']['name'] ?? '');
        $flags[] = [
            'level' => 'warn',
            'text' => $es
                ? Str::limit($name, 20, '').' no actualiza hace '.$days.' días'
                : Str::limit($name, 20, '').' hasn\'t updated in '.$days.' days',
        ];
    }

    /**
     * @param  array{object: array<string, mixed>, rows: list<array<string, mixed>>}  $entry
     * @param  list<array{level: string, text: string}>  $flags
     * @param  array<string, true>  $seenEmpty  field-name keys already flagged
     */
    private function nulls(array $entry, bool $es, array &$flags, array &$seenEmpty): void
    {
        $object = $entry['object'];
        $rows = $entry['rows'];
        if ($rows === []) {
            return;
        }
        $paths = FieldPaths::forObject($object);

        foreach ($object['fields'] ?? [] as $field) {
            if (! is_array($field)
                || ! in_array($field['type'] ?? '', ['number', 'currency', 'string', 'single_select'], true)) {
                continue;
            }
            $slug = $field['slug'] ?? null;
            $path = $paths[$field['id']] ?? $slug;
            if ($path === null && $slug === null) {
                continue;
            }
            $empty = 0;
            foreach ($rows as $row) {
                $v = $this->fieldValue($row, $path, $slug);
                if ($v === null || $v === '' || $v === []) {
                    $empty++;
                }
            }
            $rate = $empty / count($rows);
            if ($rate < self::NULL_RATE) {
                continue;
            }
            $name = (string) ($field['name'] ?? $field['slug']);
            $key = Str::lower($name);
            if (isset($seenEmpty[$key])) {
                return; // this column is already on the strip from another source
            }
            $seenEmpty[$key] = true;
            $flags[] = [
                'level' => 'info',
                'text' => $es
                    ? $name.': '.round($rate * 100).'% vacío'
                    : $name.': '.round($rate * 100).'% empty',
            ];

            return; // one completeness flag per source is enough
        }
    }

    /**
     * Read a field's value from a sampled row, tolerant of the shape the analyst
     * actually receives. ObjectRowSource yields SLUG-keyed rows — internal
     * records, and connected objects mapped through ConnectedObjectReader::mapRow
     * (external_path → slug) — so the flat slug is the real key. A dotted
     * external_path ("totals.total_tickets") would make data_get miss a slug key
     * ("totals_total_tickets") and read every row as null: a "100% vacío" lie
     * about a full column. Prefer the slug; fall back to the path for any nested
     * verbatim row.
     *
     * @param  array<string, mixed>  $row
     */
    private function fieldValue(array $row, ?string $path, ?string $slug): mixed
    {
        if ($slug !== null && array_key_exists($slug, $row)) {
            return $row[$slug];
        }

        return $path !== null ? data_get($row, $path) : null;
    }
}
