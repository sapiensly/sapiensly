<?php

namespace App\Jobs;

use App\Events\Apps\AppImportProgress;
use App\Models\App;
use App\Models\AppImport;
use App\Models\User;
use App\Services\Import\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports a spreadsheet in the background.
 *
 * Thousands of rows go through the full validated write path — each one
 * validating field types, resolving relations and firing record.created — which
 * is exactly what makes imported data trustworthy and exactly why it cannot
 * finish inside an HTTP request. Synchronously, a large file timed out with
 * some rows written and no record of which.
 *
 * The plan is REBUILT here from the same file and the same choices rather than
 * serialised into the job: a plan carries closures-worth of profiling state, and
 * rebuilding it deterministically is both simpler and the same guarantee the
 * two-step HTTP flow already relies on.
 *
 * `tries: 1` deliberately. The write path is not idempotent unless an upsert key
 * was chosen, so a retry would duplicate everything written before the failure.
 * A failed import reports what it managed rather than silently doubling it.
 */
#[Queue('imports')]
class RunSpreadsheetImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Long enough for the row cap at the write path's real pace. */
    public int $timeout = 900;

    public function __construct(
        private readonly string $importId,
        private readonly string $appId,
        private readonly ?int $userId,
        private readonly string $disk,
        private readonly string $path,
        private readonly ?string $originalName,
        private readonly ?string $objectSlug,
        private readonly ?string $objectName,
        private readonly ?string $upsertKeyHeader,
        /** @var array<string, string> */
        private readonly array $overrides,
    ) {}

    public function handle(ImportService $imports): void
    {
        $import = AppImport::find($this->importId);
        $app = App::find($this->appId);

        if ($import === null || $app === null) {
            return;
        }

        $user = $this->userId !== null ? User::find($this->userId) : null;

        try {
            // Read the BYTES, not a path. The tenant disk is frequently object
            // storage, where `path()` names nothing a file handle can open —
            // and that is the disk this file is on whenever one is configured.
            $sheet = $imports->readBytes(
                (string) Storage::disk($this->disk)->get($this->path),
                $this->originalName,
            );

            $plan = $imports->plan(
                $app,
                $sheet,
                objectSlug: $this->objectSlug,
                overrides: $this->overrides,
                upsertKeyHeader: $this->upsertKeyHeader,
                objectName: $this->objectName,
            );

            $import->forceFill([
                'status' => 'running',
                'total_rows' => count($sheet->rows),
                'object_id' => $plan->object['id'] ?? null,
                'object_name' => $plan->object['name'] ?? null,
                'truncated' => $sheet->truncated,
            ])->save();
            $this->announce($import);

            $result = $imports->run(
                $app,
                $sheet,
                $plan,
                $user,
                function (int $processed, int $created, int $updated, int $failed, array $errors) use ($import): void {
                    $import->forceFill([
                        'processed' => $processed,
                        'created_count' => $created,
                        'updated_count' => $updated,
                        'failed_count' => $failed,
                        // Kept trimmed while running: the full list is written at
                        // the end, and a progress row is not a place to accumulate
                        // thousands of messages.
                        'errors' => array_slice($errors, 0, 50),
                    ])->save();
                    $this->announce($import);
                },
            );

            $import->forceFill([
                'status' => 'completed',
                'processed' => count($sheet->rows),
                'created_count' => $result->created,
                'updated_count' => $result->updated,
                'failed_count' => $result->failed,
                'errors' => array_slice($result->errors, 0, 50),
                'finished_at' => now(),
            ])->save();
            $this->announce($import);
        } catch (Throwable $e) {
            Log::error('Spreadsheet import failed', [
                'import_id' => $this->importId,
                'app_id' => $this->appId,
                'error' => $e->getMessage(),
            ]);

            $import->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
            $this->announce($import);
        } finally {
            // The upload was only ever a courier. Keeping it would leave a copy
            // of the tenant's data in a second place for no further purpose.
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    private function announce(AppImport $import): void
    {
        try {
            AppImportProgress::dispatch($import->id, $import->toProgress());
        } catch (Throwable $e) {
            // A broadcast outage must not fail an import that is writing fine.
            Log::debug('Import progress broadcast failed', ['error' => $e->getMessage()]);
        }
    }
}
