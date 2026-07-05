<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Express\DashboardExpressPhases;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The acceptance harness for L4 Express: runs the benchmark prompt suite
 * through the pipeline SYNCHRONOUSLY against a real app + MCP source and
 * reports the design's acceptance metrics — time to v1, success rate, gate
 * latencies/tokens/fallbacks, honest substitutions, and the deliberate-halt
 * scenario. The "beats agentic Claude on all 3 axes" claim gets measured
 * here, not declared. Results also land as JSON under storage/app/benchmarks.
 */
#[Signature('benchmark:dashboards {app_slug} {--user=} {--scenario=*} {--keep : keep the created pages/objects (default rolls nothing back; pages accumulate)}')]
#[Description('Run the Express dashboard benchmark suite against an app with a live MCP source.')]
class BenchmarkDashboards extends Command
{
    /** @var array<string, string> scenario key → prompt */
    private const SCENARIOS = [
        'tickets' => 'Crea un dashboard de análisis agregado de tickets: KPIs de volumen, backlog y tiempos de resolución (mediana y P95), gráficas variadas por estado/prioridad/categoría, insights reales y filtro de fecha.',
        'semanal' => 'Quiero un tablero ejecutivo con la serie semanal de tickets y su tendencia, desglose por categoría y los principales motivos a deflectar.',
        'sla' => 'Dashboard de calidad de servicio: incumplimientos de SLA, tiempos de respuesta por prioridad y dónde se concentra el riesgo.',
        'sin_csat' => 'Dashboard de satisfacción del cliente: CSAT promedio, CSAT por categoría y su evolución semanal.',
        'incontestable' => 'Crea un dashboard financiero con revenue mensual, margen por producto y proyección de flujo de caja.',
    ];

    public function handle(ExpressPipeline $pipeline): int
    {
        $app = App::query()->where('slug', $this->argument('app_slug'))->first();
        if ($app === null) {
            $this->error("App '{$this->argument('app_slug')}' not found.");

            return self::FAILURE;
        }

        $user = $this->option('user') !== null
            ? User::query()->find((int) $this->option('user'))
            : User::query()->find($app->user_id);
        if ($user === null) {
            $this->error('No user to act as (pass --user=).');

            return self::FAILURE;
        }

        $only = (array) $this->option('scenario');
        $scenarios = $only === []
            ? self::SCENARIOS
            : array_intersect_key(self::SCENARIOS, array_flip($only));

        $rows = [];
        $report = [];
        foreach ($scenarios as $key => $prompt) {
            $this->components->task("Escenario «{$key}»", function () use ($pipeline, $app, $user, $key, $prompt, &$rows, &$report): bool {
                $conversation = BuilderConversation::create([
                    'app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active',
                ]);
                $run = PipelineRun::create([
                    'app_id' => $app->id,
                    'conversation_id' => $conversation->id,
                    'kind' => 'dashboard_express_benchmark',
                    'prompt' => $prompt,
                ]);

                $context = new ExpressContext($app->fresh(), $user, $conversation, $prompt);
                $startedAt = microtime(true);
                $run = $pipeline->execute($run, $context, DashboardExpressPhases::make());
                $seconds = round(microtime(true) - $startedAt, 1);

                $gates = collect($run->gates ?? []);
                $tokens = $gates->sum(fn (array $g) => ($g['tokens']['in'] ?? 0) + ($g['tokens']['out'] ?? 0));
                $fallbacks = $gates->filter(fn (array $g) => $g['fallback_used'] ?? false)->keys()->implode(',');

                $rows[] = [
                    $key,
                    $run->status,
                    $seconds.'s',
                    $context->page['path'] ?? '—',
                    (string) count($context->objects),
                    $tokens > 0 ? (string) $tokens : '—',
                    $fallbacks !== '' ? $fallbacks : '—',
                ];
                $report[$key] = [
                    'status' => $run->status,
                    'seconds' => $seconds,
                    'run_id' => $run->id,
                    'page' => $context->page,
                    'objects' => count($context->objects),
                    'tokens' => $tokens,
                    'gates' => $run->gates,
                    'substitutions' => $context->substitutions,
                    'notes' => $context->notes,
                ];

                return in_array($run->status, ['succeeded', 'halted_unanswerable'], true);
            });
        }

        $this->table(['Escenario', 'Estado', 'Tiempo', 'Página', 'Objetos', 'Tokens', 'Fallbacks'], $rows);

        $succeeded = collect($report)->where('status', 'succeeded');
        $expectedHalts = collect($report)->only(['incontestable'])->where('status', 'halted_unanswerable');
        $this->components->twoColumnDetail('Éxito (build)', $succeeded->count().'/'.max(count($report) - 1, 0));
        $this->components->twoColumnDetail('Halt honesto (incontestable)', $expectedHalts->isNotEmpty() ? 'sí' : 'NO');
        if ($succeeded->isNotEmpty()) {
            $this->components->twoColumnDetail('Tiempo p50', $succeeded->pluck('seconds')->median().'s');
            $this->components->twoColumnDetail('Tokens promedio', (int) $succeeded->avg('tokens'));
        }

        $path = 'benchmarks/express_'.now()->format('Ymd_His').'.json';
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info("Reporte: storage/app/{$path}");

        return self::SUCCESS;
    }
}
