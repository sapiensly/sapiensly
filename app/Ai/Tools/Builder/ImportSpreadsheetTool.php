<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\User;
use App\Services\Import\ImportPlan;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteSheetFetcher;
use App\Services\Import\SheetData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Imports real data from a spreadsheet the user pointed at — a Google Sheets
 * link, any CSV/XLSX URL, or content pasted straight into the conversation.
 *
 * This is the tool that turns an empty app into the user's actual business. It
 * does the whole job in one call: read the file, infer each column's type,
 * create the object (or map onto an existing one) and write the rows through
 * the same validated path a form uses.
 *
 * `dry_run` exists because an import is hard to take back. The model can look
 * at the inferred schema first and tell the user what it found before a single
 * row is written.
 */
class ImportSpreadsheetTool implements Tool
{
    public function __construct(
        private App $appModel,
        private ImportService $imports,
        private RemoteSheetFetcher $remote,
        private ?User $user = null,
    ) {}

    public function name(): string
    {
        return 'import_spreadsheet';
    }

    public function description(): string
    {
        return 'Import real data from a spreadsheet into this app. Pass EITHER `url` (a Google Sheets share link, or any link to a CSV/XLSX file) OR `content` (delimited text pasted by the user). '
            .'Leave `object_slug` empty to CREATE a new object from the file — every column becomes a field, typed from its actual values (prices become currency, dates become date, repeating values become a choice list). '
            .'Pass `object_slug` to import into an object that already exists; columns are matched to fields by name and anything unmatched is reported as skipped, never invented. '
            .'Pass `upsert_key_column` (a column name from the file) to UPDATE existing records that match on it instead of creating duplicates — use it whenever the user re-imports a corrected or updated file. '
            .'Set `dry_run: true` FIRST when the user has not seen the file: it returns the inferred schema, the column mapping and any warnings WITHOUT writing anything, so you can confirm before importing. '
            .'The response reports created/updated/failed counts and lists failing rows BY THEIR LINE NUMBER in the file — report those honestly, never round a partial import up to a success.';
    }

    public function handle(Request $request): string
    {
        $input = $request->all();

        $url = trim((string) ($input['url'] ?? ''));
        $content = (string) ($input['content'] ?? '');

        if ($url === '' && trim($content) === '') {
            return json_encode(['ok' => false, 'error' => 'Pass either `url` or `content`.']);
        }

        try {
            $sheet = $url !== ''
                ? $this->remote->fetch($url)
                : $this->imports->readString($content);
        } catch (Throwable $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }

        if ($sheet->headers === [] || $sheet->rows === []) {
            return json_encode([
                'ok' => false,
                'error' => 'That file has no readable rows. The first row must name the columns.',
            ]);
        }

        $objectSlug = trim((string) ($input['object_slug'] ?? ''));
        $upsertColumn = trim((string) ($input['upsert_key_column'] ?? ''));

        try {
            $plan = $this->imports->plan(
                $this->appModel,
                $sheet,
                objectSlug: $objectSlug !== '' ? $objectSlug : null,
                upsertKeyHeader: $upsertColumn !== '' ? $upsertColumn : null,
                objectName: trim((string) ($input['object_name'] ?? '')) ?: null,
            );
        } catch (Throwable $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }

        if ((bool) ($input['dry_run'] ?? false)) {
            return json_encode([
                'ok' => true,
                'dry_run' => true,
                'plan' => $this->planDigest($plan, $sheet),
                'note' => 'Nothing was written. Call again without dry_run to import.',
            ]);
        }

        try {
            $result = $this->imports->run($this->appModel, $sheet, $plan, $this->user);
        } catch (Throwable $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }

        return json_encode([
            'ok' => true,
            'summary' => $result->summary(),
            ...$result->toArray(),
            'object' => ['slug' => $plan->object['slug'], 'name' => $plan->object['name']],
            'created_object' => $plan->mode === ImportPlan::MODE_CREATE,
            'skipped_columns' => $this->skippedColumns($plan),
            'warnings' => $plan->warnings,
        ]);
    }

    /**
     * A compact plan the model can narrate — the columns, what each became, and
     * what would be ignored. Deliberately smaller than the full plan: the model
     * needs to describe it, not reconstruct it.
     *
     * @return array<string, mixed>
     */
    private function planDigest(ImportPlan $plan, SheetData $sheet): array
    {
        return [
            'mode' => $plan->mode,
            'object' => ['slug' => $plan->object['slug'], 'name' => $plan->object['name']],
            'rows' => $plan->totalRows,
            'truncated' => $plan->truncated,
            'upsert_key' => $plan->upsertKey,
            'columns' => array_map(fn ($m): array => [
                'header' => $m->header,
                'field' => $m->fieldSlug,
                'type' => $m->type,
                'skipped_because' => $m->skipReason,
                'samples' => $m->profile->samples,
            ], $plan->mappings),
            'warnings' => $plan->warnings,
            'sample_rows' => $sheet->sampleRows(3),
        ];
    }

    /**
     * @return list<array{header: string, reason: string}>
     */
    private function skippedColumns(ImportPlan $plan): array
    {
        $skipped = [];
        foreach ($plan->mappings as $mapping) {
            if ($mapping->fieldSlug === null) {
                $skipped[] = ['header' => $mapping->header, 'reason' => (string) $mapping->skipReason];
            }
        }

        return $skipped;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Link to the spreadsheet — a Google Sheets share URL, or any CSV/XLSX file URL. Use this OR `content`.'),
            'content' => $schema->string()->description('Delimited text pasted by the user (first line = column names). Use this OR `url`.'),
            'object_slug' => $schema->string()->description('Import into this EXISTING object. Leave empty to create a new object from the file.'),
            'object_name' => $schema->string()->description('Name for the object being created (e.g. "Clientes"). Ignored when object_slug is set.'),
            'upsert_key_column' => $schema->string()->description('A column NAME from the file that identifies an existing record. Rows matching it are UPDATED instead of duplicated.'),
            'dry_run' => $schema->boolean()->description('true to return the inferred schema and mapping WITHOUT writing anything.'),
        ];
    }
}
