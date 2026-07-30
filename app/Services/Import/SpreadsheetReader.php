<?php

namespace App\Services\Import;

use League\Csv\Info;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Turns an uploaded file into a {@see SheetData}. Everything here exists
 * because of how real exports actually look, not how the format is specified:
 *
 *  - Excel in a Spanish locale writes CSV with `;`, not `,`. The delimiter is
 *    sniffed, never assumed.
 *  - Files come out of legacy systems in Windows-1252/latin1. Read as UTF-8
 *    they turn accented names into mojibake, silently, in every row.
 *  - A UTF-8 BOM makes the first header "\u{FEFF}Nombre", which then matches no
 *    field and looks like a mapping bug.
 *  - A spreadsheet date is a serial number (45292), meaningless without the
 *    cell's format. That is the one thing the file knows better than we do, so
 *    dates are resolved here and handed on as ISO.
 *
 * Bounded by construction: a read filter caps rows before PhpSpreadsheet
 * materialises them, so a 500k-row upload cannot exhaust memory.
 */
class SpreadsheetReader
{
    /** Rows kept from one file. Beyond this the import is reported as truncated. */
    public const MAX_ROWS = 5000;

    /** Columns kept. A sheet wider than this is almost never a data table. */
    public const MAX_COLUMNS = 100;

    private const CSV_DELIMITERS = [',', ';', "\t", '|'];

    /**
     * @throws RuntimeException when the file cannot be read as a spreadsheet
     */
    public function readFile(string $path, ?string $originalName = null): SheetData
    {
        if (! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        return $this->isSpreadsheetBinary($path, $originalName)
            ? $this->readSpreadsheet($path)
            : $this->readCsv((string) file_get_contents($path));
    }

    /**
     * Read delimited text held in memory — the path pasted content takes.
     */
    public function readString(string $contents): SheetData
    {
        return $this->readCsv($contents);
    }

    /**
     * Read bytes of unknown format — what a download from a URL hands us, where
     * the "csv" in the link says nothing about what actually arrived.
     * A spreadsheet is spooled to a temp file because PhpSpreadsheet reads from
     * a path, never from a string.
     */
    public function readBytes(string $contents, ?string $originalName = null): SheetData
    {
        $isBinary = str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, "\xD0\xCF\x11\xE0");
        if (! $isBinary) {
            return $this->readCsv($contents);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'sp_import');
        try {
            file_put_contents($path, $contents);

            return $this->readSpreadsheet($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Trust the bytes over the extension: a "sheet.csv" exported from Google
     * Drive is frequently a real XLSX, and a file with no extension at all is
     * the normal case for a download.
     */
    private function isSpreadsheetBinary(string $path, ?string $originalName): bool
    {
        $handle = fopen($path, 'rb');
        $magic = $handle !== false ? (string) fread($handle, 8) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        // XLSX/ODS are ZIP containers; XLS is an OLE2 compound file.
        if (str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            return true;
        }

        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        return in_array($extension, ['xlsx', 'xls', 'ods'], true);
    }

    private function readCsv(string $contents): SheetData
    {
        $contents = $this->toUtf8($contents);

        $reader = Reader::fromString($contents);
        $reader->setDelimiter($this->sniffDelimiter($reader));

        // Records are read POSITIONALLY, and the header is mapped by us: a real
        // export can repeat a column name ("Total" twice), and league/csv's own
        // header mapping refuses that outright. Two identical headers are a
        // file to import, not an error to raise.
        $headers = [];
        $rows = [];
        $total = 0;

        foreach ($reader->getRecords() as $record) {
            $values = array_values($record);

            if ($headers === []) {
                // Skip any blank lines above the header row.
                if (array_filter($values, fn ($v): bool => trim((string) $v) !== '') === []) {
                    continue;
                }
                $headers = $this->normalizeHeaders($values);

                continue;
            }

            $row = $this->mapRecord($values, $headers);
            if ($this->isBlank($row)) {
                continue;
            }

            $total++;
            if (count($rows) < self::MAX_ROWS) {
                $rows[] = $row;
            }
        }

        return new SheetData($headers, $rows, $total, $total > count($rows), 'csv');
    }

    /**
     * Normalise to UTF-8. mb_detect_encoding is asked for UTF-8 first and
     * strictly, so a file that IS valid UTF-8 is never "converted" from a
     * single-byte guess — that conversion is what double-encodes accents.
     */
    private function toUtf8(string $contents): string
    {
        // Strip the UTF-8 BOM before anything else looks at the first header.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $detected = mb_detect_encoding($contents, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)
            ?: 'Windows-1252';

        return (string) mb_convert_encoding($contents, 'UTF-8', $detected);
    }

    /**
     * Pick the delimiter that yields the most consistent column count. Falls
     * back to a comma when the file is a single column (every candidate ties
     * at zero), which is the only case where the choice cannot matter.
     */
    private function sniffDelimiter(Reader $reader): string
    {
        $stats = Info::getDelimiterStats($reader, self::CSV_DELIMITERS, 10);
        arsort($stats);

        $best = array_key_first($stats);

        return ($best !== null && $stats[$best] > 0) ? (string) $best : ',';
    }

    private function readSpreadsheet(string $path): SheetData
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('That file is not a spreadsheet we can read: '.$e->getMessage());
        }

        // Not read-data-only: the cell FORMAT is what distinguishes a date from
        // the number 45292, and dropping styles loses every date in the file.
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= SpreadsheetReader::MAX_ROWS + 1;
            }
        });

        try {
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            throw new RuntimeException('That spreadsheet could not be opened: '.$e->getMessage());
        }

        $matrix = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(false);

            $line = [];
            foreach ($cells as $cell) {
                $line[] = $this->cellValue($cell);
                if (count($line) >= self::MAX_COLUMNS) {
                    break;
                }
            }
            $matrix[] = $line;
            if (count($matrix) > self::MAX_ROWS + 1) {
                break;
            }
        }

        // The header is the first row with any content: exports routinely open
        // with a blank line or a title row above the real table.
        $headerIndex = null;
        foreach ($matrix as $i => $line) {
            if (array_filter($line, fn ($v) => trim((string) $v) !== '') !== []) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            return new SheetData([], [], 0, false, 'xlsx');
        }

        $headers = $this->normalizeHeaders($matrix[$headerIndex]);

        $rows = [];
        $total = 0;
        foreach (array_slice($matrix, $headerIndex + 1) as $line) {
            $row = $this->mapRecord($line, $headers);
            if ($this->isBlank($row)) {
                continue;
            }
            $total++;
            if (count($rows) < self::MAX_ROWS) {
                $rows[] = $row;
            }
        }

        return new SheetData($headers, $rows, $total, $total > count($rows), 'xlsx');
    }

    /**
     * One cell as a string. A formula contributes its computed result (falling
     * back to the value cached in the file when recalculation fails — a
     * cross-sheet reference the read filter trimmed away, say), and a date
     * contributes ISO rather than its serial number.
     */
    private function cellValue(Cell $cell): ?string
    {
        try {
            $value = $cell->getCalculatedValue();
        } catch (\Throwable) {
            $value = $cell->getValue();
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            $date = ExcelDate::excelToDateTimeObject((float) $value);

            // A whole serial is a plain date; a fraction carries a time.
            return ((float) $value == floor((float) $value))
                ? $date->format('Y-m-d')
                : $date->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim((string) $value);
    }

    /**
     * Header names a column can actually be addressed by: trimmed, blanks given
     * a positional name, and duplicates suffixed. A spreadsheet with two
     * "Total" columns is common and must not silently collapse into one.
     *
     * @param  array<int, mixed>  $raw
     * @return list<string>
     */
    private function normalizeHeaders(array $raw): array
    {
        $headers = [];
        $seen = [];

        foreach (array_values($raw) as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                $name = 'column_'.($i + 1);
            }

            $candidate = $name;
            $n = 2;
            while (isset($seen[$candidate])) {
                $candidate = $name.' ('.$n.')';
                $n++;
            }
            $seen[$candidate] = true;
            $headers[] = $candidate;

            if (count($headers) >= self::MAX_COLUMNS) {
                break;
            }
        }

        return $headers;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    private function mapRecord(array $values, array $headers): array
    {
        $values = array_values($values);
        $row = [];
        foreach ($headers as $i => $header) {
            $value = $values[$i] ?? null;
            $value = $value === null ? null : trim((string) $value);
            $row[$header] = ($value === null || $value === '') ? null : $value;
        }

        return $row;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }
}
