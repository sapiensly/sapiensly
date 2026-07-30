<?php

namespace App\Services\Import;

/**
 * A spreadsheet reduced to the only shape the rest of the import cares about:
 * a list of column headers and the rows under them, every cell a string or null.
 *
 * Values arrive as STRINGS on purpose. What a cell means ("1.234,56" is a
 * number in Spain and something else entirely in the US) is a decision the
 * profiler makes with the whole column in view — deciding it cell by cell while
 * parsing loses exactly the context needed to get it right. The one exception is
 * a real date in a spreadsheet, which arrives already normalised to ISO because
 * the file itself told us it was a date.
 */
final class SheetData
{
    /**
     * @param  list<string>  $headers  de-duplicated, in file order
     * @param  list<array<string, string|null>>  $rows  keyed by header
     * @param  int  $totalRows  rows in the file (before any cap)
     * @param  bool  $truncated  whether the cap dropped rows
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly int $totalRows,
        public readonly bool $truncated,
        public readonly string $format,
    ) {}

    /**
     * Every value in one column, blanks included — what the profiler reads.
     *
     * @return list<string|null>
     */
    public function column(string $header): array
    {
        return array_map(fn (array $row) => $row[$header] ?? null, $this->rows);
    }

    /**
     * The first few rows, for a preview a human can eyeball before committing.
     *
     * @return list<array<string, string|null>>
     */
    public function sampleRows(int $limit = 5): array
    {
        return array_slice($this->rows, 0, $limit);
    }
}
