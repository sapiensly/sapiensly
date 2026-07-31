<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Queue forensics: the jobs that failed, newest first, with the job class, the queue it was on, when it died and the first lines of its exception. Filter by queue, by job class (partial match) or by a search over the exception text. Use it when platform_health reports failed jobs, or when work someone queued (an AI turn, an import, a workflow) never produced a result. Pass a uuid to get that job\'s FULL exception and payload instead of the truncated list view. Read-only — retry or discard with retry_failed_job.')]
class ListFailedJobsTool extends SysadminTool
{
    private const EXCERPT_LENGTH = 400;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'queue' => ['sometimes', 'nullable', 'string', 'max:80'],
            'job' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = DB::connection('platform')->table('failed_jobs');

            if (! empty($validated['uuid'])) {
                $row = $query->where('uuid', $validated['uuid'])->first();

                if ($row === null) {
                    return Response::error("No failed job with uuid '{$validated['uuid']}'.");
                }

                return Response::json([
                    'job' => [
                        'uuid' => $row->uuid,
                        'queue' => $row->queue,
                        'connection' => $row->connection,
                        'failed_at' => $row->failed_at,
                        'name' => $this->jobName($row->payload),
                        'exception' => (string) $row->exception,
                        'payload' => json_decode((string) $row->payload, true),
                    ],
                ]);
            }

            if (! empty($validated['queue'])) {
                $query->where('queue', $validated['queue']);
            }
            if (! empty($validated['search'])) {
                $query->whereRaw('lower(exception) like ?', ['%'.strtolower($validated['search']).'%']);
            }
            if (! empty($validated['job'])) {
                // The job class lives inside the JSON payload; a LIKE over the
                // raw text finds it without decoding every row in the database.
                $query->whereRaw('lower(payload) like ?', ['%'.strtolower($validated['job']).'%']);
            }

            $total = (clone $query)->count();

            $rows = $query
                ->orderByDesc('failed_at')
                ->limit((int) ($validated['limit'] ?? 20))
                ->get();
        } catch (Throwable $e) {
            return Response::error('Could not read the failed-jobs table: '.$e->getMessage());
        }

        return Response::json([
            'total_matching' => $total,
            'returned' => $rows->count(),
            'jobs' => $rows->map(fn ($row) => [
                'uuid' => $row->uuid,
                'name' => $this->jobName($row->payload),
                'queue' => $row->queue,
                'connection' => $row->connection,
                'failed_at' => $row->failed_at,
                'exception_excerpt' => Str::limit((string) $row->exception, self::EXCERPT_LENGTH),
            ])->values(),
        ]);
    }

    /**
     * The job class from a serialized queue payload, or null when the payload
     * is not in a shape we recognize (never throw over a log-reading tool).
     */
    private function jobName(?string $payload): ?string
    {
        $decoded = json_decode((string) $payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $decoded['displayName']
            ?? ($decoded['data']['commandName'] ?? null);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Return this one job in full (complete exception + payload) instead of the list.'),
            'queue' => $schema->string()->description('Only jobs from this queue (default, ai, workflows, imports, …).'),
            'job' => $schema->string()->description('Only jobs whose payload mentions this text — normally a job class name.'),
            'search' => $schema->string()->description('Only jobs whose exception contains this text.'),
            'limit' => $schema->integer()->description('How many to return, 1-100. Default 20.'),
        ];
    }
}
