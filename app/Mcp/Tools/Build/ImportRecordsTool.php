<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Import\ImportPlan;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteSheetFetcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Import real data into an app from a spreadsheet: pass `url` (a Google Sheets share link or any CSV/XLSX file URL) or `content` (delimited text). Leave `object_slug` empty to CREATE a new object from the file — each column becomes a field typed from its real values. Pass `object_slug` to load an existing object; columns match fields by name and unmatched ones are reported as skipped, never invented. `upsert_key_column` updates matching records instead of duplicating them. Use `dry_run` first to see the inferred schema without writing. Rows are written through the same validated path the UI uses, and failures are reported by their line number in the file.')]
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
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $url = trim((string) ($validated['url'] ?? ''));
        $content = (string) ($validated['content'] ?? '');
        if ($url === '' && trim($content) === '') {
            return Response::error('Pass either `url` or `content`.');
        }

        $imports = app(ImportService::class);

        try {
            $sheet = $url !== ''
                ? app(RemoteSheetFetcher::class)->fetch($url)
                : $imports->readString($content);
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

        try {
            $result = $imports->run($app, $sheet, $plan, $user);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'summary' => $result->summary(),
            ...$result->toArray(),
            'app_slug' => $app->slug,
            'object' => ['slug' => $plan->object['slug'], 'name' => $plan->object['name']],
            'created_object' => $plan->mode === ImportPlan::MODE_CREATE,
            'warnings' => $plan->warnings,
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
            'dry_run' => $schema->boolean()->description('true to return the inferred schema and mapping without writing.'),
        ];
    }
}
