<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Services\Platform\PlatformProbe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Live health of the running platform: is Horizon supervising, is Reverb accepting WebSocket connections, Redis reachability + memory + client count, Postgres version/connections/size/pgvector, per-queue pending depth, failed-job total, and how long the oldest queued job has been waiting. Every probe degrades instead of failing — a dead subsystem reports as unreachable rather than erroring the call. Read-only. When queue_pending is high and horizon_running is false, workers died without draining: list_failed_jobs then run_platform_maintenance horizon:terminate.')]
class PlatformHealthTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $probe = app(PlatformProbe::class);
        $queues = $probe->queueDepths();
        $redis = $probe->redis();
        $database = $probe->database();

        $horizon = $probe->horizonRunning();
        $reverb = $probe->reverbReachable();

        $warnings = [];
        if (! $horizon) {
            $warnings[] = 'Horizon is not running — queued work (AI turns, imports, workflows) will not be processed.';
        }
        if (! $reverb) {
            $warnings[] = 'Reverb is unreachable — token streaming and live updates will not reach browsers.';
        }
        if (! $redis['reachable']) {
            $warnings[] = 'Redis is unreachable — cache, queues, sessions and broadcasting are all affected.';
        }
        if ($queues['failed'] > 0) {
            $warnings[] = $queues['failed'].' failed job(s) waiting — inspect with list_failed_jobs.';
        }
        if (($queues['oldest_pending_seconds'] ?? 0) > 600) {
            $warnings[] = 'The oldest queued job has been waiting over 10 minutes — workers may be stuck or absent.';
        }

        return Response::json([
            'ok' => $warnings === [],
            'warnings' => $warnings,
            'services' => [
                'horizon' => ['running' => $horizon],
                'reverb' => ['reachable' => $reverb],
                'redis' => $redis,
                'database' => $database,
            ],
            'queues' => $queues,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
