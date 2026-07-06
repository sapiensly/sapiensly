<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Connected\IntegrationCatalog;
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
use Illuminate\Support\Str;

/**
 * The acceptance harness for L4 Express: runs the benchmark prompt suite
 * through the pipeline SYNCHRONOUSLY against a real app + MCP source and
 * reports the design's acceptance metrics — time to v1, success rate, gate
 * latencies/tokens/fallbacks, honest substitutions, and the deliberate-halt
 * scenario. The "beats agentic Claude on all 3 axes" claim gets measured
 * here, not declared. Results also land as JSON under storage/app/benchmarks.
 */
#[Signature('benchmark:dashboards {app_slug} {--user=} {--scenario=*} {--prompt=* : run these custom prompts as scenarios instead of the derived suite} {--judge : score each built dashboard 1-5 with an LLM judge} {--keep : keep the created pages/objects (default rolls nothing back; pages accumulate)}')]
#[Description('Run the Express dashboard benchmark suite against an app with a live MCP source.')]
class BenchmarkDashboards extends Command
{
    /**
     * Candidate off-domain topics for the deliberate-halt scenario: the first
     * whose keywords never appear in the source's tool names is guaranteed
     * unanswerable BY that source.
     *
     * @var array<string, list<string>>
     */
    private const OFF_DOMAIN_CANDIDATES = [
        'finanzas corporativas con revenue mensual, margen por producto y proyección de flujo de caja' => ['finan', 'revenue', 'margen', 'cash', 'flujo'],
        'nómina y vacaciones de empleados con headcount por equipo' => ['nomina', 'payroll', 'vacacion', 'headcount', 'empleado'],
        'campañas de marketing con CTR, CPC y conversión por canal pagado' => ['marketing', 'campaign', 'ctr', 'cpc', 'ads'],
        'clima organizacional y rotación de personal' => ['clima', 'rotacion', 'attrition', 'enps'],
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

        $scenarios = $this->buildScenarios($user);
        if ($scenarios === []) {
            $this->error('No authorized MCP integration with tools — nothing to benchmark against.');

            return self::FAILURE;
        }
        $only = (array) $this->option('scenario');
        if ($only !== []) {
            $scenarios = array_intersect_key($scenarios, array_flip($only));
        }

        $this->components->info('Escenarios (derivados de la fuente):');
        foreach ($scenarios as $key => $prompt) {
            $this->components->twoColumnDetail($key, Str::limit($prompt, 90));
        }

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
     * The scenario suite, derived from what the source ACTUALLY covers so the
     * benchmark makes sense on any domain (tickets, NPS, OTD, inventory…) —
     * the original hardcoded ticket prompts read absurd against other apps.
     * --prompt overrides everything with custom scenarios; the deliberate
     * halt keeps its 'incontestable' key so the summary check still works.
     *
     * @return array<string, string>
     */
    private function buildScenarios(User $user): array
    {
        $custom = array_values(array_filter((array) $this->option('prompt'), fn ($p) => is_string($p) && trim($p) !== ''));
        if ($custom !== []) {
            $scenarios = [];
            foreach ($custom as $i => $prompt) {
                $scenarios['custom_'.($i + 1)] = trim($prompt);
            }

            return $scenarios;
        }

        $integration = Integration::query()
            ->forAccountContext($user)
            ->where('is_mcp', true)
            ->where('status', '!=', 'draft')
            ->orderBy('name')
            ->first();
        if ($integration === null) {
            return [];
        }

        try {
            $tools = app(IntegrationCatalog::class)->tools($integration, $user);
        } catch (\Throwable) {
            return [];
        }
        if ($tools === []) {
            return [];
        }

        // Domain words: the significant tokens of the tool names, most
        // frequent first ("tickets", "nps", "otd", "sellers"...).
        $generic = ['tool', 'get', 'list', 'search', 'global', 'with', 'status', 'report', 'metrics', 'time', 'series', 'daily', 'weekly', 'overview', 'compare', 'dimension', 'by'];
        $words = collect($tools)
            ->flatMap(fn (array $t) => preg_split('/[-_]/', (string) $t['name']))
            ->map(fn ($w) => Str::lower((string) $w))
            ->filter(fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, $generic, true))
            ->countBy()->sortDesc()->keys();
        $primary = (string) ($words->first() ?? 'operación');
        $secondary = (string) ($words->skip(1)->first() ?? $primary);

        // Deliberate halt: the first off-domain topic with ZERO keyword
        // overlap against the tool names.
        $haystack = Str::lower(collect($tools)->pluck('name')->implode(' '));
        $offDomain = 'finanzas corporativas con revenue mensual y proyección de flujo de caja';
        foreach (self::OFF_DOMAIN_CANDIDATES as $topic => $keywords) {
            if (collect($keywords)->every(fn (string $k) => ! str_contains($haystack, $k))) {
                $offDomain = $topic;
                break;
            }
        }

        return [
            'general' => "Crea un dashboard ejecutivo de análisis de {$primary}: métricas clave, tendencias, desgloses relevantes, insights con conclusiones reales y filtro de fecha.",
            'tendencia' => "Quiero un tablero con la evolución semanal de {$primary} y sus principales desgloses por dimensión.",
            'diagnostico' => "Dashboard de diagnóstico de {$secondary}: dónde se concentran los problemas u oportunidades y qué acciones tomar.",
            'incontestable' => "Crea un dashboard de {$offDomain}.",
        ];
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
                    'titulo' => $context->page['name'] ?? '',
                    // The re-audit's rendered numbers — result['rendered'] is
                    // only written by the queued job, and an empty resumen made
                    // the judge score a healthy board 1/5 for "no data".
                    'resumen' => array_slice($sanity['summary'] ?? [], 0, 40),
                    'sustituciones' => $context->substitutions,
                    'notas' => $context->notes,
                ], JSON_UNESCAPED_UNICODE),
                fn ($schema) => [
                    'score' => $schema->integer()->min(1)->max(5),
                    'reasons' => $schema->string()->description('OBLIGATORIO: 2-4 porqués concretos citando números del resumen — un score sin porqués no sirve para diagnosticar.'),
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
