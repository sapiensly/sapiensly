<?php

namespace App\Jobs;

use App\Models\App;
use App\Models\AppExport;
use App\Models\User;
use App\Services\Apps\AppAccessResolver;
use App\Services\Export\RecordExporter;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Produce an export file in the background.
 *
 * Memory was never the constraint — the exporter pages — so this exists for the
 * clock: past a few hundred thousand rows the request timeout is what fails,
 * and it fails after the user has already waited. Here nothing is racing a
 * proxy.
 *
 * The permission is RE-RESOLVED here rather than carried in the payload. A job
 * that trusted a serialised access context would still be using it minutes
 * later, after a role could have been narrowed or a policy rewritten; resolving
 * it now means the file reflects what that role may see when the rows are
 * actually read.
 *
 * `tries: 1`: a retry would rewrite the same file from scratch for no gain, and
 * a failed export should be visible as failed rather than quietly attempted
 * three times.
 */
#[Queue('exports')]
class RunRecordExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly string $exportId,
        private readonly string $appId,
        private readonly ?int $userId,
        private readonly ?string $roleSlug,
        /** @var array<string, mixed> */
        private readonly array $params,
    ) {}

    public function handle(
        AppManifestService $manifests,
        AppAccessResolver $accessResolver,
        RecordExporter $exporter,
    ): void {
        $export = AppExport::find($this->exportId);
        $app = App::find($this->appId);

        if ($export === null || $app === null) {
            return;
        }

        $temp = null;

        try {
            $manifest = $manifests->getActiveManifest($app);
            if ($manifest === null) {
                throw new \RuntimeException('This app has no published manifest.');
            }

            $object = null;
            foreach ($manifest['objects'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === $export->object_id) {
                    $object = $candidate;
                    break;
                }
            }
            if ($object === null) {
                throw new \RuntimeException('That object no longer exists in this app.');
            }

            $user = $this->userId !== null ? User::find($this->userId) : null;
            $access = $accessResolver->resolve($app, $manifest, $user, $this->roleSlug);

            // The same refusal the live download makes. A role narrowed since
            // the export was requested must not produce a file anyway.
            if (! $access->hasAccess || ! $access->can($object['id'], 'read')) {
                throw new \RuntimeException('That role can no longer read this object.');
            }

            $export->forceFill(['status' => 'running'])->save();

            $context = [
                'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
                'params' => $this->params,
                '__access' => $access,
                '__actor' => $user,
            ];

            $temp = tempnam(sys_get_temp_dir(), 'sp_export');
            $rows = $exporter->toFile($temp, $app, $object, $manifest, $context, (string) $export->format);

            $fileName = RecordExporter::filename($object, (string) $export->format);
            $path = 'exports/'.$app->id.'/'.$export->id.'/'.$fileName;

            // Streamed onto the disk rather than read into a string: the whole
            // point of the queued path is that the file may be large.
            $handle = fopen($temp, 'r');
            Storage::disk((string) $export->disk)->put($path, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            $export->forceFill([
                'status' => 'completed',
                'rows_written' => $rows,
                'storage_path' => $path,
                'file_name' => $fileName,
                'size_bytes' => filesize($temp) ?: null,
                'object_name' => $object['name'] ?? $object['slug'] ?? null,
                'expires_at' => now()->addHours(AppExport::TTL_HOURS),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('Record export failed', [
                'export_id' => $this->exportId,
                'app_id' => $this->appId,
                'error' => $e->getMessage(),
            ]);

            $export->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
