<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Express\DashboardExpressPhases;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressPipeline;
use App\Services\Express\GateRunner;
use App\Services\Express\QualityAudit;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantContext;
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
#[Signature('benchmark:dashboards {app_slug} {--user=} {--scenario=*} {--judge : score each built dashboard 1-5 with an LLM judge} {--keep : keep the created pages/objects (default rolls nothing back; pages accumulate)}')]
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

    public function handle(ExpressPipeline $pipeline, QualityAudit $audit, GateRunner $gates): int
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

        // CLI has no BindTenantContext middleware — set the RLS scope for the
        // app's owner explicitly or every tenant-table write fails closed.
        app(TenantContext::class)->set($app->organization_id, $app->user_id);

        $rows = [];
        $report = [];
        foreach ($scenarios as $key => $prompt) {
            $this->components->task("Escenario «{$key}»", function () use ($pipeline, $audit, $gates, $app, $user, $key, $prompt, &$rows, &$report): bool {
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

                $gateData = collect($run->gates ?? []);
                $tokens = $gateData->sum(fn (array $g) => ($g['tokens']['in'] ?? 0) + ($g['tokens']['out'] ?? 0));
                $fallbacks = $gateData->filter(fn (array $g) => $g['fallback_used'] ?? false)->keys()->implode(',');

                // Quality: re-audit the final page (post-repair) and, when
                // asked, have an LLM judge score story-usefulness 1-5.
                $quality = null;
                if ($run->status === 'succeeded' && $context->page !== null) {
                    $quality = $this->scoreQuality($audit, $gates, $run, $context, $user, (bool) $this->option('judge'));
                }

                $rows[] = [
                    $key,
                    $run->status,
                    $seconds.'s',
                    $context->page['path'] ?? '—',
                    (string) count($context->objects),
                    $tokens > 0 ? (string) $tokens : '—',
                    $fallbacks !== '' ? $fallbacks : '—',
                    $quality !== null
                        ? ($quality['sanity_issues'] === 0 ? '✓' : $quality['sanity_issues'].' issues')
                            .($quality['judge_score'] !== null ? ' · juez '.$quality['judge_score'].'/5' : '')
                        : '—',
                    $run->error !== null ? mb_substr($run->error, 0, 60) : '—',
                ];
                $report[$key] = [
                    'status' => $run->status,
                    'error' => $run->error,
                    'quality' => $quality,
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

        $this->table(['Escenario', 'Estado', 'Tiempo', 'Página', 'Objetos', 'Tokens', 'Fallbacks', 'Calidad', 'Error'], $rows);

        $succeeded = collect($report)->where('status', 'succeeded');
        $expectedHalts = collect($report)->only(['incontestable'])->where('status', 'halted_unanswerable');
        $this->components->twoColumnDetail('Éxito (build)', $succeeded->count().'/'.max(count($report) - 1, 0));
        $this->components->twoColumnDetail('Halt honesto (incontestable)', $expectedHalts->isNotEmpty() ? 'sí' : 'NO');
        if ($succeeded->isNotEmpty()) {
            $this->components->twoColumnDetail('Tiempo p50', $succeeded->pluck('seconds')->median().'s');
            $this->components->twoColumnDetail('Tokens promedio', (int) $succeeded->avg('tokens'));
        }

        $withQuality = collect($report)->pluck('quality')->filter();
        if ($withQuality->isNotEmpty()) {
            $this->components->twoColumnDetail('Sanity issues (post-reparación)', (string) $withQuality->sum('sanity_issues'));
            $judged = $withQuality->pluck('judge_score')->filter();
            if ($judged->isNotEmpty()) {
                $this->components->twoColumnDetail('Juez (promedio)', round($judged->avg(), 1).'/5');
            }
        }

        $path = 'benchmarks/express_'.now()->format('Ymd_His').'.json';
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info("Reporte: storage/app/{$path}");

        return self::SUCCESS;
    }

    /**
     * Deterministic sanity re-audit of the FINAL page (post-repair) + an
     * optional LLM judge scoring how useful the story is for the request.
     *
     * @return array{sanity_issues: int, blocks_audited: int, issue_kinds: list<string>, judge_score: int|null, judge_reasons: ?string}
     */
    private function scoreQuality(QualityAudit $audit, GateRunner $gates, PipelineRun $run, ExpressContext $context, User $user, bool $withJudge): array
    {
        $sanity = ['issues' => [], 'blocks_audited' => 0];
        try {
            $manifests = app(AppManifestService::class);
            $manifest = $manifests->getActiveManifest($context->app->fresh());
            $page = collect($manifest['pages'] ?? [])->firstWhere('slug', $context->page['slug']);
            if (is_array($page)) {
                $sanity = $audit->audit($context->app->fresh(), $page, $manifest, $user);
            }
        } catch (\Throwable $e) {
            $sanity['issues'][] = ['kind' => 'audit_failed', 'detail' => $e->getMessage(), 'block_id' => '', 'repairable' => false];
        }

        $judgeScore = null;
        $judgeReasons = null;
        if ($withJudge) {
            $result = $gates->run(
                $run, 'quality_judge',
                <<<'TXT'
Eres un analista de BI exigente. Recibes el PEDIDO del usuario, el RESUMEN de
la página construida y los NÚMEROS renderizados. Califica 1-5 la UTILIDAD
ANALÍTICA del dashboard para ese pedido: ¿los números responden las preguntas
del pedido? ¿la historia se lee (magnitud → concentración → acción)? ¿hay
métricas de relleno? 5 = lo usaría un director tal cual; 1 = números sin
sentido. Sé estricto y di los porqués concretos.
TXT,
                json_encode([
                    'pedido' => $run->prompt,
                    'resumen' => $run->result['rendered'] ?? [],
                    'sustituciones' => $context->substitutions,
                    'notas' => $context->notes,
                ], JSON_UNESCAPED_UNICODE),
                fn ($schema) => [
                    'score' => $schema->integer()->min(1)->max(5),
                    'reasons' => $schema->string(),
                ],
                ['score' => 0, 'reasons' => 'judge unavailable'],
                $user,
                config('express.plumbing_model'),
            );
            $judgeScore = (int) ($result['output']['score'] ?? 0) ?: null;
            $judgeReasons = $result['output']['reasons'] ?? null;
        }

        return [
            'sanity_issues' => count($sanity['issues']),
            'blocks_audited' => $sanity['blocks_audited'],
            'issue_kinds' => collect($sanity['issues'])->pluck('kind')->unique()->values()->all(),
            'judge_score' => $judgeScore,
            'judge_reasons' => $judgeReasons,
        ];
    }
}
