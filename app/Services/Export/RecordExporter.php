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

    /**
     * Rows a DIRECT download will produce inside the request. Memory is flat at
     * any size, so this is purely about the clock: past here the request timeout
     * becomes the real risk and the caller is sent to the queued route instead.
     */
    public const DIRECT_MAX_ROWS = 100000;

    public function __construct(
        private readonly RecordQueryService $records,
    ) {}

    /**
     * @param  array<string, mixed>  $object
     */
    public static function filename(array $object, string $format): string
    {
        return ($object['slug'] ?? 'datos').'-'.now()->format('Y-m-d').'.'.($format === 'xlsx' ? 'xlsx' : 'csv');
    }

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
        $filename = self::filename($object, $format);

        if ($format === 'xlsx') {
            $this->assertXlsxFits($app, $object, $manifest, $context, $query);

            return $this->streamXlsx($app, $object, $manifest, $context, $query, $columns, $filename);
        }

        return $this->streamCsv($app, $object, $manifest, $context, $query, $columns, $filename);
    }

    /**
     * How many rows this export would produce for this role — what the caller
     * needs to decide between downloading now and queueing.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     */
    public function countFor(App $app, array $object, array $manifest, array $context, array $query = []): int
    {
        return $this->records->count($app, ['object_id' => $object['id']] + $query, $manifest, $context);
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
            $this->writeCsv($handle, $app, $object, $manifest, $context, $query, $columns, flushing: true);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Write the whole file to a stream. Shared by the direct download and the
     * queued job, so a file produced in the background is byte-for-byte the one
     * the browser would have got — there is no second code path to drift.
     *
     * `$flushing` only differs by WHERE the bytes are going: pushing them out
     * matters for a live response and is meaningless writing to a temp file.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $columns
     * @return int rows written
     */
    private function writeCsv(mixed $handle, App $app, array $object, array $manifest, array $context, array $query, array $columns, bool $flushing): int
    {
        // The BOM is what makes Excel open a UTF-8 CSV without turning every
        // accent into mojibake — the same failure this codebase already handles
        // on the way in, seen from the other side.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($columns));

        $written = 0;
        foreach ($this->rows($app, $object, $manifest, $context, $query) as $data) {
            fputcsv($handle, array_map(
                fn (string $slug): string => $this->scalar($data[$slug] ?? null),
                array_keys($columns),
            ));

            // Push the bytes out every page. Without this the "stream" only
            // streams as far as PHP's output buffer: the client waits for the
            // last row before seeing the first, an idle proxy can time the
            // connection out, and the memory the generator was written to save
            // accumulates in the buffer instead of the result set.
            if ($flushing && ++$written % self::PAGE === 0) {
                $this->flush($handle);
            } elseif (! $flushing) {
                $written++;
            }
        }

        if ($flushing) {
            $this->flush($handle);
        }

        return $written;
    }

    /**
     * Produce the file at a local path — what the queued job needs, since there
     * is no response to stream into.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     * @return int rows written
     *
     * @throws InvalidArgumentException when XLSX is asked for beyond its ceiling
     */
    public function toFile(
        string $path,
        App $app,
        array $object,
        array $manifest,
        array $context,
        string $format = 'csv',
        array $query = [],
    ): int {
        $columns = $this->columns($object, $context);

        if ($format === 'xlsx') {
            $this->assertXlsxFits($app, $object, $manifest, $context, $query);
            $book = $this->buildXlsx($app, $object, $manifest, $context, $query, $columns);
            (new XlsxWriter($book))->save($path);
            $rows = $book->getActiveSheet()->getHighestRow() - 1;
            $book->disconnectWorksheets();

            return max(0, $rows);
        }

        $handle = fopen($path, 'w');
        $written = $this->writeCsv($handle, $app, $object, $manifest, $context, $query, $columns, flushing: false);
        fclose($handle);

        return $written;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     *
     * @throws InvalidArgumentException
     */
    private function assertXlsxFits(App $app, array $object, array $manifest, array $context, array $query): void
    {
        $total = $this->records->count($app, ['object_id' => $object['id']] + $query, $manifest, $context);
        if ($total > self::XLSX_MAX_ROWS) {
            throw new InvalidArgumentException(
                "That is {$total} rows — more than a spreadsheet file can hold here (".self::XLSX_MAX_ROWS.'). Export as CSV, or filter first.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $columns
     */
    private function buildXlsx(App $app, array $object, array $manifest, array $context, array $query, array $columns): Spreadsheet
    {
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

        return $book;
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
            $book = $this->buildXlsx($app, $object, $manifest, $context, $query, $columns);
            (new XlsxWriter($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Hand the bytes written so far to the client. Guarded because a closed or
     * absent buffer is normal under some SAPIs and test harnesses, and an
     * export must not die over the plumbing of its own progress.
     *
     * @param  resource  $handle
     */
    private function flush(mixed $handle): void
    {
        fflush($handle);

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
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
