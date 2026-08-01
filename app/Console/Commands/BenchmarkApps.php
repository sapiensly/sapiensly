<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use App\Support\Locale\Inflector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The acceptance harness for the app builder: generate every app in the
 * benchmark suite from its description, score what came out against what the
 * description asked for, and report a defect rate.
 *
 * It exists because the alternative is what we were doing — generating one app,
 * looking at it, and forming an impression. That found real defects (dates a
 * day early across the Americas, KPIs named after the wrong thing, tables too
 * wide to read) but it cannot tell you whether a change made things better on
 * the whole, only whether it fixed the app in front of you.
 *
 * Every check here is DETERMINISTIC. A model grading its own homework measures
 * the grader; these are structural facts about the manifest, held against
 * expectations a person wrote in resources/benchmarks/app-suite.php.
 *
 * Results land as JSON under storage/app/benchmarks so a later run can be
 * diffed against them (--baseline), which is the only way to see a regression
 * in something this noisy: the model does not answer the same way twice.
 */
#[Signature('benchmark:apps {--case=* : run only these suite keys} {--user= : the user id to build as (default: the first sysadmin)} {--keep : leave the generated apps behind for inspection} {--baseline= : a prior results file to diff against} {--repeat=1 : generate each case N times, to see how much the model varies}')]
#[Description('Generate the benchmark app suite and score it against what each description asked for.')]
class BenchmarkApps extends Command
{
    /**
     * A table wider than this stops being scannable — the same ceiling the
     * manifest linter holds authors to.
     */
    private const MAX_SCANNABLE_COLUMNS = 8;

    /** Visualisations a dashboard may carry before it reads as a wall. */
    private const MAX_DASHBOARD_CHARTS = 4;

    public function handle(): int
    {
        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $suite = $this->suite();
        if ($suite === []) {
            $this->error('No cases to run.');

            return self::FAILURE;
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $this->info(sprintf(
            'Building %d app(s)%s as %s.',
            count($suite) * $repeat,
            $repeat > 1 ? " ({$repeat} runs each)" : '',
            $user->email,
        ));

        $results = [];
        $startedAt = microtime(true);

        // Session-level scope, not runScoped(): that opens a transaction, and a
        // benchmark is a long series of LLM calls — exactly what its docblock
        // says to keep outside one.
        app(TenantContext::class)->set($user->organization_id, $user->id);

        foreach ($suite as $case) {
            foreach (range(1, $repeat) as $run) {
                $results[] = $this->runCase($case, $run, $user);
            }
        }

        $report = $this->report($results, microtime(true) - $startedAt);
        $this->render($report);

        if (($baseline = $this->option('baseline')) !== null) {
            $this->renderDiff($report, $baseline);
        }

        $path = 'benchmarks/apps-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('  Written to '.Storage::disk('local')->path($path));

        return $report['totals']['defects'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Generate one app and score it. A case that cannot be built at all is the
     * worst outcome there is, so it counts as a defect rather than an absence.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function runCase(array $case, int $run, User $user): array
    {
        $label = $case['key'].($run > 1 ? "#{$run}" : '');
        $this->line("  <fg=gray>building</> {$label}…");

        $slug = 'bench_'.Str::of($case['key'])->slug('_').'_'.Str::lower(Str::random(6));
        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => $slug,
            'name' => $case['name'],
            'description' => $case['description'],
        ]);

        $coercions = [];
        $startedAt = microtime(true);

        try {
            $base = app(AppManifestService::class)->initialManifest($app);
            $base['settings']['default_locale'] = $case['locale'];
            $manifest = app(AppScaffolder::class)->scaffold($base, $case['description'], $user, $coercions);
            app(AppManifestService::class)->createVersion($app, $manifest, $user, 'Benchmark');
        } catch (\Throwable $e) {
            $app->delete();

            return [
                'case' => $case['key'],
                'run' => $run,
                'built' => false,
                'seconds' => round(microtime(true) - $startedAt, 1),
                'findings' => [['check' => 'build_failed', 'detail' => $e->getMessage()]],
                'coercions' => $coercions,
            ];
        }

        $findings = $this->score($manifest, $case);

        if (! $this->option('keep')) {
            $app->delete();
        }

        return [
            'case' => $case['key'],
            'run' => $run,
            'built' => true,
            'app_slug' => $this->option('keep') ? $slug : null,
            'seconds' => round(microtime(true) - $startedAt, 1),
            'objects' => array_map(fn (array $o): string => $o['name'], $manifest['objects']),
            'findings' => $findings,
            // Not defects: a coercion is the scaffolder telling the truth about
            // something it had to change. Counted so a run that suddenly stops
            // reporting any is visible — silence here was its own bug once.
            'coercions' => $coercions,
        ];
    }

    /**
     * Every deterministic check, against one built manifest.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $case
     * @return list<array{check: string, detail: string}>
     */
    private function score(array $manifest, array $case): array
    {
        $findings = [];
        $add = function (string $check, string $detail) use (&$findings): void {
            $findings[] = ['check' => $check, 'detail' => $detail];
        };

        $validation = app(ManifestValidator::class)->validate($manifest);
        foreach ($validation->errors as $error) {
            $add('manifest_invalid', $error->path.': '.$error->message);
        }
        foreach ($validation->warnings as $warning) {
            if ($warning->code === 'design_smell') {
                $add('design_smell', $warning->path.': '.Str::limit($warning->message, 120));
            }
        }

        // Did the app get the entities the description plainly described?
        $names = array_map(fn (array $o): string => (string) $o['name'], $manifest['objects']);
        foreach ($case['expect']['objects'] as $expected) {
            if (! $this->namedAmong($expected, $names)) {
                $add('object_missing', "nothing named like \"{$expected}\" (got: ".implode(', ', $names).')');
            }
        }

        // Does the dashboard answer what the description asked to SEE?
        $dashboard = collect($manifest['pages'] ?? [])->firstWhere('slug', 'dashboard');
        if ($dashboard === null) {
            $add('dashboard_missing', 'the app has no dashboard page');
        } else {
            $surfaced = $this->objectsSurfacedOn($dashboard, $manifest);
            foreach ($case['expect']['dashboard'] as $expected) {
                if (! $this->namedAmong($expected, $surfaced)) {
                    $add('intent_unanswered', "the description asks to see \"{$expected}\"; the dashboard shows ".(
                        $surfaced === [] ? 'nothing' : implode(', ', $surfaced)
                    ));
                }
            }

            $charts = collect($dashboard['blocks'] ?? [])
                ->whereIn('type', ['chart', 'sparkline'])
                ->count();
            if ($charts > self::MAX_DASHBOARD_CHARTS) {
                $add('chart_budget_exceeded', "{$charts} visualisations on the dashboard");
            }
        }

        foreach ($this->tablesIn($manifest) as $table) {
            $visible = collect($table['columns'] ?? [])
                ->reject(fn (array $c): bool => ($c['type'] ?? null) === 'action')
                ->reject(fn (array $c): bool => ($c['hidden_by_default'] ?? false) === true)
                ->count();
            if ($visible > self::MAX_SCANNABLE_COLUMNS) {
                $add('table_too_wide', "a table shows {$visible} columns at once");
            }
        }

        // A Spanish brief that comes back with an object called "Leases" has
        // drifted, and the slug is in the URL the user reads.
        foreach ($manifest['objects'] as $object) {
            if (! $this->slugEchoesName((string) $object['slug'], (string) $object['name'])) {
                $add('slug_language_drift', "\"{$object['name']}\" has the slug \"{$object['slug']}\"");
            }
        }

        // Every object the app holds should be reachable.
        $reachable = collect($manifest['pages'] ?? [])
            ->flatMap(fn (array $p): Collection => $this->objectIdsIn($p['blocks'] ?? []))
            ->unique();
        foreach ($manifest['objects'] as $object) {
            if (! $reachable->contains($object['id'])) {
                $add('object_unreachable', "\"{$object['name']}\" appears on no page");
            }
        }

        return $findings;
    }

    /**
     * Which objects a page actually talks about, by name — the union of every
     * block that points at one. A KPI counts as surfacing an object just as
     * much as a chart does; the question is whether the reader is told.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function objectsSurfacedOn(array $page, array $manifest): array
    {
        $byId = collect($manifest['objects'])->keyBy('id');

        return $this->objectIdsIn($page['blocks'] ?? [])
            ->map(fn (string $id): ?string => $byId[$id]['name'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Object ids referenced anywhere in a block tree, including the ones nested
     * in tabs and split layouts — a top-level scan once missed every board in
     * the app, and a benchmark that undercounts flatters the thing it measures.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return Collection<int, string>
     */
    private function objectIdsIn(array $blocks): Collection
    {
        $ids = collect();

        foreach ($blocks as $block) {
            foreach ([$block['object_id'] ?? null, $block['data_source']['object_id'] ?? null, $block['query']['object_id'] ?? null] as $id) {
                if (is_string($id)) {
                    $ids->push($id);
                }
            }
            foreach ($block['items'] ?? [] as $item) {
                if (is_string($item['query']['object_id'] ?? null)) {
                    $ids->push($item['query']['object_id']);
                }
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                $ids = $ids->merge($this->objectIdsIn($block[$key] ?? []));
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $child) {
                    $ids = $ids->merge($this->objectIdsIn($child['blocks'] ?? []));
                }
            }
        }

        return $ids;
    }

    /**
     * Every table in the manifest, nested ones included.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function tablesIn(array $manifest): array
    {
        $walk = function (array $blocks) use (&$walk): array {
            $out = [];
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'table') {
                    $out[] = $block;
                }
                foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                    $out = [...$out, ...$walk($block[$key] ?? [])];
                }
                foreach (['tabs', 'sections'] as $key) {
                    foreach ($block[$key] ?? [] as $child) {
                        $out = [...$out, ...$walk($child['blocks'] ?? [])];
                    }
                }
            }

            return $out;
        };

        $tables = [];
        foreach ($manifest['pages'] ?? [] as $page) {
            $tables = [...$tables, ...$walk($page['blocks'] ?? [])];
        }

        return $tables;
    }

    /**
     * Is any of `$names` the thing `$expected` describes? Accents folded,
     * singular and plural treated as the same word, and a stem match so
     * "Órdenes de servicio" answers for "ordenes".
     *
     * @param  list<string>  $names
     */
    private function namedAmong(string $expected, array $names): bool
    {
        $needle = $this->fold($expected);

        foreach ($names as $name) {
            $hay = $this->fold($name);
            if ($hay === $needle) {
                return true;
            }
            // Head-noun match in either direction: the expectation names the
            // concept, the app may qualify it ("Órdenes de compra").
            $head = fn (string $s): string => (string) Str::of($s)->before(' ');
            if ($head($hay) !== '' && ($head($hay) === $head($needle)
                || str_starts_with($hay, $needle)
                || str_starts_with($needle, $head($hay)))) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, unaccented, singularised — the form two words can be compared in. */
    private function fold(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return Inflector::singular($ascii, 'es');
    }

    /**
     * A slug is meant to be the object's own name, machine-shaped. When it
     * shares no opening with the name, the model has translated one and not the
     * other — which is how a Spanish app ends up serving /leases.
     */
    private function slugEchoesName(string $slug, string $name): bool
    {
        $slugHead = $this->fold(str_replace('_', ' ', $slug));
        $nameHead = $this->fold($name);
        $stem = fn (string $s): string => mb_substr((string) Str::of($s)->before(' '), 0, 4);

        return $stem($slugHead) !== '' && str_starts_with($stem($slugHead), $stem($nameHead))
            || str_starts_with($stem($nameHead), $stem($slugHead));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function report(array $results, float $seconds): array
    {
        $defects = collect($results)->sum(fn (array $r): int => count($r['findings']));
        $byCheck = collect($results)
            ->flatMap(fn (array $r): array => $r['findings'])
            ->countBy('check')
            ->sortDesc()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'totals' => [
                'apps' => count($results),
                'built' => collect($results)->where('built', true)->count(),
                'defects' => $defects,
                'defects_per_app' => count($results) > 0 ? round($defects / count($results), 2) : 0.0,
                'clean_apps' => collect($results)->filter(fn (array $r): bool => $r['findings'] === [])->count(),
                'seconds' => round($seconds, 1),
            ],
            'by_check' => $byCheck,
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        $this->newLine();
        foreach ($report['results'] as $result) {
            $status = $result['findings'] === []
                ? '<fg=green>clean</>'
                : '<fg=red>'.count($result['findings']).' finding(s)</>';
            $this->line(sprintf('  %-22s %-24s %5.1fs', $result['case'], $status, $result['seconds']));
            foreach ($result['findings'] as $finding) {
                $this->line("      <fg=yellow>{$finding['check']}</> — ".Str::limit($finding['detail'], 140));
            }
        }

        $t = $report['totals'];
        $this->newLine();
        $this->line("  <options=bold>{$t['clean_apps']}/{$t['apps']} clean · {$t['defects']} defects · {$t['defects_per_app']} per app · {$t['seconds']}s</>");
        foreach ($report['by_check'] as $check => $count) {
            $this->line("    {$check}: {$count}");
        }
    }

    /**
     * The number that matters is not today's — it is today's against the last
     * one. The model does not answer the same way twice, so a single run says
     * much less than a comparison does.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderDiff(array $report, string $baselinePath): void
    {
        $raw = is_file($baselinePath)
            ? file_get_contents($baselinePath)
            : (Storage::disk('local')->exists($baselinePath) ? Storage::disk('local')->get($baselinePath) : null);

        if ($raw === null || ! is_array($baseline = json_decode((string) $raw, true))) {
            $this->warn("  Baseline '{$baselinePath}' could not be read — no comparison.");

            return;
        }

        $before = $baseline['totals']['defects_per_app'] ?? null;
        $after = $report['totals']['defects_per_app'];
        $this->newLine();
        $this->line('  <options=bold>vs baseline</>');
        $this->line(sprintf('    defects per app: %s → %s', $before ?? '?', $after));

        $checks = collect($baseline['by_check'] ?? [])->keys()
            ->merge(collect($report['by_check'])->keys())
            ->unique();
        foreach ($checks as $check) {
            $wasCount = (int) ($baseline['by_check'][$check] ?? 0);
            $nowCount = (int) ($report['by_check'][$check] ?? 0);
            if ($wasCount === $nowCount) {
                continue;
            }
            $colour = $nowCount < $wasCount ? 'green' : 'red';
            $this->line("    <fg={$colour}>{$check}: {$wasCount} → {$nowCount}</>");
        }
    }

    /** @return list<array<string, mixed>> */
    private function suite(): array
    {
        $suite = require base_path('resources/benchmarks/app-suite.php');
        $only = array_filter((array) $this->option('case'));

        return $only === []
            ? $suite
            : array_values(array_filter($suite, fn (array $c): bool => in_array($c['key'], $only, true)));
    }

    private function resolveUser(): ?User
    {
        if (($id = $this->option('user')) !== null) {
            $user = User::query()->find((int) $id);
            if ($user === null) {
                $this->error("No user with id {$id}.");
            }

            return $user;
        }

        $user = User::query()->whereNotNull('organization_id')->orderBy('id')->first();
        if ($user === null) {
            $this->error('No user with an organization to build as — pass --user=.');
        }

        return $user;
    }
}
