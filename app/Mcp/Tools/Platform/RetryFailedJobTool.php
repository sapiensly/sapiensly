<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Push a failed job back onto its queue, or discard it. Actions: retry (re-queue — it runs again, so a job with real-world side effects performs them AGAIN), discard (delete the failed record without running it). Target one job by uuid, or every failed job on one queue with queue=<name>. There is deliberately no "retry everything" — name a uuid or a queue. Confirm with the user before retrying jobs that send mail, call external APIs or move money. Audited.')]
class RetryFailedJobTool extends SysadminTool
{
    private const MAX_BULK = 200;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:retry,discard'],
            'uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'queue' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $action = $validated['action'];
        $uuid = $validated['uuid'] ?? null;
        $queue = $validated['queue'] ?? null;

        if ($uuid === null && $queue === null) {
            return Response::error('Name the job to act on: pass `uuid` for one job, or `queue` for every failed job on that queue.');
        }

        try {
            $query = DB::connection('platform')->table('failed_jobs');
            $uuid !== null ? $query->where('uuid', $uuid) : $query->where('queue', $queue);

            $uuids = $query->orderByDesc('failed_at')->limit(self::MAX_BULK)->pluck('uuid')->all();
        } catch (Throwable $e) {
            return Response::error('Could not read the failed-jobs table: '.$e->getMessage());
        }

        if ($uuids === []) {
            return Response::error($uuid !== null
                ? "No failed job with uuid '{$uuid}'."
                : "No failed jobs on queue '{$queue}'.");
        }

        $done = [];
        $failed = [];

        foreach ($uuids as $id) {
            try {
                $exitCode = $action === 'retry'
                    ? Artisan::call('queue:retry', ['id' => [$id]])
                    : Artisan::call('queue:forget', ['id' => $id]);

                $exitCode === 0 ? $done[] = $id : $failed[] = $id;
            } catch (Throwable) {
                $failed[] = $id;
            }
        }

        $this->audit(
            actor: $user,
            summary: sprintf('%s %d failed job(s)%s', $action, count($done), $queue !== null ? " on queue '{$queue}'" : ''),
            meta: ['action' => $action, 'queue' => $queue, 'uuids' => $done, 'failed' => $failed],
            targetType: 'failed_job',
            targetId: $uuid,
            targetLabel: $queue,
        );

        return Response::json([
            'action' => $action,
            'affected' => count($done),
            'uuids' => $done,
            'could_not_process' => $failed,
            'truncated' => count($uuids) === self::MAX_BULK,
            'note' => $action === 'retry'
                ? 'Re-queued. They run only if workers are up — check platform_health.'
                : 'Discarded. The failed records are gone; the work was never performed.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('retry | discard')->required(),
            'uuid' => $schema->string()->description('The failed job to act on (from list_failed_jobs).'),
            'queue' => $schema->string()->description('Act on every failed job on this queue instead (capped at 200 per call).'),
        ];
    }
}
