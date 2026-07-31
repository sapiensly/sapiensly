<?php

namespace App\Services\Export;

use App\Models\App;
use App\Models\Record;
use App\Services\Apps\AppAccessContext;
use App\Services\Records\RecordQueryService;
use Generator;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The other half of the import: getting the rows back out.
 *
 * Everything here is gated by the SAME {@see AppAccessContext} the screen is,
 * so an export can never be a way around a permission the UI enforces — the
 * role's row_filter narrows which rows leave, and its hidden fields are absent
 * from the file entirely rather than blanked. An export that returned more than
 * the table showed would be the most quietly damaging bug in the product.
 *
 * CSV STREAMS; XLSX DOES NOT, and that asymmetry is the format's, not ours:
 * a spreadsheet is a zip archive built in memory, so it is capped and refuses
 * past the ceiling with a message pointing at CSV. Refusing beats truncating —
 * "I exported my customers and three thousand are missing" is a data-integrity
 * trap that looks like success.
 */
class RecordExporter
{
    /** Rows read per query while streaming. */
    private const PAGE = 500;

    /** Ceiling for XLSX, which PhpSpreadsheet materialises in memory. */
    public const XLSX_MAX_ROWS = 20000;

    public function __construct(
        private readonly RecordQueryService $records,
    ) {}

    /**
     * @param  array<string, mixed>  $object  the manifest object definition
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context  must carry __access
     * @param  array<string, mixed>  $query  extra filter/sort, already translated
     *
     * @throws InvalidArgumentException when XLSX is asked for beyond its ceiling
     */
    public function stream(
        App $app,
        array $object,
        array $manifest,
        array $context,
        string $format = 'csv',
        array $query = [],
    ): StreamedResponse {
        $columns = $this->columns($object, $context);
        $filename = ($object['slug'] ?? 'datos').'-'.now()->format('Y-m-d').'.'.($format === 'xlsx' ? 'xlsx' : 'csv');

        if ($format === 'xlsx') {
            $total = $this->records->count($app, ['object_id' => $object['id']] + $query, $manifest, $context);
            if ($total > self::XLSX_MAX_ROWS) {
                throw new InvalidArgumentException(
                    "That is {$total} rows — more than a spreadsheet file can hold here (".self::XLSX_MAX_ROWS.'). Export as CSV, or filter first.',
                );
            }

            return $this->streamXlsx($app, $object, $manifest, $context, $query, $columns, $filename);
        }

        return $this->streamCsv($app, $object, $manifest, $context, $query, $columns, $filename);
    }

    /**
     * The columns that leave, in manifest order, minus what the role hides.
     *
     * Derived fields (formula/lookup/rollup) are INCLUDED: they are what the
     * table shows, and a file missing the totals someone came for is not the
     * table they exported.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $context
     * @return array<string, string> slug => header label
     */
    private function columns(array $object, array $context): array
    {
        $access = $context['__access'] ?? null;
        $hidden = $access instanceof AppAccessContext
            ? $access->hiddenFieldSlugs($object['id'])
            : [];

        $columns = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (in_array($field['slug'], $hidden, true)) {
                continue;
            }
            $columns[$field['slug']] = $field['name'] ?? $field['slug'];
        }

        return $columns;
    }

    /**
     * Page through the rows the role may see. A generator so CSV never holds
     * more than one page, whatever the object's size.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(App $app, array $object, array $manifest, array $context, array $query): Generator
    {
        $offset = 0;

        while (true) {
            $page = $this->records->query(
                $app,
                ['object_id' => $object['id'], 'limit' => self::PAGE, 'offset' => $offset] + $query,
                $manifest,
                $context,
            );

            if ($page->isEmpty()) {
                return;
            }

            foreach ($page as $record) {
                yield $record instanceof Record ? ($record->data ?? []) : [];
            }

            if ($page->count() < self::PAGE) {
                return;
            }
            $offset += self::PAGE;
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $columns
     */
    private function streamCsv(App $app, array $object, array $manifest, array $context, array $query, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($app, $object, $manifest, $context, $query, $columns): void {
            $handle = fopen('php://output', 'w');

            // The BOM is what makes Excel open a UTF-8 CSV without turning
            // every accent into mojibake — the same failure this codebase
            // already handles on the way in, seen from the other side.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_values($columns));

            foreach ($this->rows($app, $object, $manifest, $context, $query) as $data) {
                fputcsv($handle, array_map(
                    fn (string $slug): string => $this->scalar($data[$slug] ?? null),
                    array_keys($columns),
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $columns
     */
    private function streamXlsx(App $app, array $object, array $manifest, array $context, array $query, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($app, $object, $manifest, $context, $query, $columns): void {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle(mb_substr((string) ($object['name'] ?? 'Datos'), 0, 31));

            $sheet->fromArray(array_values($columns), null, 'A1');

            $row = 2;
            foreach ($this->rows($app, $object, $manifest, $context, $query) as $data) {
                $sheet->fromArray(
                    array_map(fn (string $slug) => $data[$slug] ?? null, array_keys($columns)),
                    null,
                    'A'.$row,
                );
                $row++;
            }

            (new XlsxWriter($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Flatten a stored value for a text cell. A multi_select is a list and a
     * file is an object, and neither means anything to a spreadsheet as JSON —
     * they become the readable form a person would have typed.
     */
    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            // A list of choices joins; anything richer (a file payload) shows
            // its name rather than its plumbing.
            $flat = array_filter($value, 'is_scalar');

            return $flat === $value
                ? implode(', ', $value)
                : (string) ($value['original_name'] ?? $value['name'] ?? '');
        }

        return (string) $value;
    }
}
