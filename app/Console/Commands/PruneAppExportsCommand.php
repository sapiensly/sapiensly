<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\AppExport;
use App\Services\Storage\TenantStorage;
use App\Support\Tenancy\TenantScopes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the files behind expired exports, then the runs themselves once the
 * history stops being useful.
 *
 * An export is a copy of tenant data living outside the database. Without this,
 * every download quietly becomes a second, unmanaged store of the same rows —
 * one that keeps working long after whoever asked for it stopped needing it,
 * and long after the role that produced it may have been narrowed.
 *
 * Two stages on purpose: the FILE goes at its expiry, the ROW survives a while
 * so "I exported this on Tuesday" stays answerable after the bytes are gone.
 */
#[Signature('exports:prune {--days=30 : Delete export runs older than this}')]
#[Description('Delete expired export files and old export runs.')]
class PruneAppExportsCommand extends Command
{
    public function handle(TenantStorage $storage): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $files = 0;
        $rows = 0;

        // Scoped per tenant: without this the tenant connection runs unscoped,
        // RLS returns nothing, and the command reports success having pruned
        // exactly zero.
        TenantScopes::each(App::query(), function () use ($storage, $cutoff, &$files, &$rows): void {
            AppExport::query()
                ->whereNotNull('storage_path')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->chunkById(100, function ($expired) use ($storage, &$files): void {
                    foreach ($expired as $export) {
                        $this->deleteFile($storage, $export, $files);
                    }
                });

            $rows += AppExport::query()->where('created_at', '<', $cutoff)->delete();
        });

        $this->info("Pruned {$files} expired export file(s) and {$rows} old run(s).");

        return self::SUCCESS;
    }

    private function deleteFile(TenantStorage $storage, AppExport $export, int &$files): void
    {
        try {
            $disk = $storage->diskFromName((string) $export->disk);
            if ($disk->exists((string) $export->storage_path)) {
                $disk->delete((string) $export->storage_path);
                $files++;
            }
        } catch (\Throwable $e) {
            // A disk that no longer resolves must not stall the sweep — but the
            // row is NOT cleared, so the file stays accounted for and the next
            // run tries again.
            Log::warning('Could not delete an expired export file', [
                'export_id' => $export->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $export->forceFill([
            'storage_path' => null,
            'disk' => null,
            'size_bytes' => null,
        ])->save();
    }
}
