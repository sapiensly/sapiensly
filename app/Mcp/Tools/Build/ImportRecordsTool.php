<?php

namespace App\Mcp\Tools\Build;

use App\Jobs\RunSpreadsheetImportJob;
use App\Mcp\Tools\SapiensTool;
use App\Models\AppImport;
use App\Models\User;
use App\Services\Import\ImportPlan;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteSheetFetcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Import real data into an app from a spreadsheet: pass `url` (a Google Sheets share link or any CSV/XLSX file URL) or `content` (delimited text). Leave `object_slug` empty to CREATE a new object from the file — each column becomes a field typed from its real values. Pass `object_slug` to load an existing object; columns match fields by name and unmatched ones are reported as skipped, never invented. `upsert_key_column` updates matching records instead of duplicating them. `column_overrides` corrects a wrong auto-match. Use `dry_run` FIRST to see the inferred schema and mapping without writing. Importing RUNS ASYNC — it returns an import_id; call this tool again with just `app_slug` and `import_id` to read progress, and keep polling until `finished` is true. Rows go through the same validated path the UI uses, and failures are reported by their line number in the file.')]
class ImportRecordsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'url' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'object_slug' => ['sometimes', 'nullable', 'string'],
            'object_name' => ['sometimes', 'nullable', 'string'],
            'upsert_key_column' => ['sometimes', 'nullable', 'string'],
            'column_overrides' => ['sometimes', 'nullable', 'array'],
            'column_overrides.*' => ['string'],
            'dry_run' => ['sometimes', 'boolean'],
            'import_id' => ['sometimes', 'nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        // Reading progress on a run already started. Checked before the input
        // requirements below: polling needs no file, only the id it was given.
        $importId = trim((string) ($validated['import_id'] ?? ''));
        if ($importId !== '') {
            $import = AppImport::query()->where('app_id', $app->id)->where('id', $importId)->first();

            return $import === null
                ? Response::error("No import '{$importId}' on '{$app->slug}'.")
                : Response::json($import->toProgress());
        }

        $url = trim((string) ($validated['url'] ?? ''));
        $content = (string) ($validated['content'] ?? '');
        if ($url === '' && trim($content) === '') {
            return Response::error('Pass either `url` or `content`.');
        }

        $imports = app(ImportService::class);

        // Keep the RAW bytes: the job re-reads the file from disk, so the
        // download cannot be thrown away after parsing it once here.
        try {
            $raw = $url !== '' ? app(RemoteSheetFetcher::class)->fetchRaw($url) : $content;
            $sheet = $imports->readBytes($raw, $url !== '' ? $url : null);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        if ($sheet->headers === [] || $sheet->rows === []) {
            return Response::error('That file has no readable rows — the first row must name the columns.');
        }

        $objectSlug = trim((string) ($validated['object_slug'] ?? ''));
        $upsertColumn = trim((string) ($validated['upsert_key_column'] ?? ''));

        try {
            $plan = $imports->plan(
                $app,
                $sheet,
                objectSlug: $objectSlug !== '' ? $objectSlug : null,
                overrides: $validated['column_overrides'] ?? [],
                upsertKeyHeader: $upsertColumn !== '' ? $upsertColumn : null,
                objectName: trim((string) ($validated['object_name'] ?? '')) ?: null,
            );
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        if ((bool) ($validated['dry_run'] ?? false)) {
            return Response::json([
                'dry_run' => true,
                'plan' => $plan->toArray(),
                'note' => 'Nothing was written. Call again without dry_run to import.',
            ]);
        }

        // Queued, like the UI. Thousands of rows through the full validated
        // write path take minutes, and an agent that blocks its own call on
        // that hits the same wall the browser used to.
        $stashed = $imports->stash($app, $raw, $url !== '' ? pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) : 'csv');

        $import = AppImport::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'file_name' => $url !== '' ? basename(parse_url($url, PHP_URL_PATH) ?: 'import') : 'pasted.csv',
            'status' => 'queued',
        ]);

        RunSpreadsheetImportJob::dispatch(
            $import->id,
            $app->id,
            $user->id,
            $stashed['disk'],
            $stashed['path'],
            $import->file_name,
            $objectSlug !== '' ? $objectSlug : null,
            trim((string) ($validated['object_name'] ?? '')) ?: null,
            $upsertColumn !== '' ? $upsertColumn : null,
            $validated['column_overrides'] ?? [],
        );

        return Response::json([
            'queued' => true,
            'app_slug' => $app->slug,
            'import_id' => $import->id,
            'rows' => count($sheet->rows),
            'object' => ['slug' => $plan->object['slug'], 'name' => $plan->object['name']],
            'creates_object' => $plan->mode === ImportPlan::MODE_CREATE,
            'warnings' => $plan->warnings,
            'note' => 'Call import_records again with app_slug + import_id to read progress; keep polling until finished is true.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The app to import into.')->required(),
            'url' => $schema->string()->description('Google Sheets share link, or any CSV/XLSX file URL. Use this OR `content`.'),
            'content' => $schema->string()->description('Delimited text; the first line names the columns. Use this OR `url`.'),
            'object_slug' => $schema->string()->description('Import into this existing object. Omit to create a new object from the file.'),
            'object_name' => $schema->string()->description('Name for the object being created. Ignored when object_slug is set.'),
            'upsert_key_column' => $schema->string()->description('Column NAME from the file identifying an existing record — matching rows are updated, not duplicated.'),
            'column_overrides' => $schema->object()->description('Correct a wrong auto-match: {"<column name in the file>": "<field slug>"}. Read the dry run first, then override only what it got wrong.'),
            'dry_run' => $schema->boolean()->description('true to return the inferred schema and mapping without writing.'),
            'import_id' => $schema->string()->description('Read the progress of a run this tool already started. Pass it with app_slug and nothing else.'),
        ];
    }
}
