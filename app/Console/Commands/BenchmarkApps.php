<?php

namespace App\Console\Commands;

use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\AppActionExecutor;
use App\Services\Records\BlockDataResolver;
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

        $spend = $this->spendOn($app);
        $smokeChecks = 0;
        $findings = [
            ...$this->score($manifest, $case),
            ...$this->smoke($app, $manifest, $user, $smokeChecks),
        ];

        if (! $this->option('keep')) {
            $app->delete();
        }

        return [
            'case' => $case['key'],
            'run' => $run,
            'built' => true,
            'app_slug' => $this->option('keep') ? $slug : null,
            'seconds' => round(microtime(true) - $startedAt, 1),
            // What this app cost to design. Read from the ledger the scaffolder
            // bills, so it is the real charge and not an estimate of one.
            'spend' => $spend,
            'objects' => array_map(fn (array $o): string => $o['name'], $manifest['objects']),
            // How many runtime assertions the smoke pass actually made. A
            // harness that cannot tell "passed" from "never ran" is worse than
            // no harness, because it reports the same word for both.
            'smoke_checks' => $smokeChecks,
            'findings' => $findings,
            // Not defects: a coercion is the scaffolder telling the truth about
            // something it had to change. Counted so a run that suddenly stops
            // reporting any is visible — silence here was its own bug once.
            'coercions' => $coercions,
        ];
    }

    /**
     * What designing this app actually cost.
     *
     * Read off the ledger the scaffolder bills rather than estimated from token
     * counts here: the ledger already knows the model's rates, whether the call
     * ran on the org's own key, and what the cache absorbed. `estimated` rides
     * along so a run whose provider did not report usage cannot masquerade as a
     * measured one.
     *
     * @return array{cost: float, input_tokens: int, output_tokens: int, model: ?string, estimated: bool}
     */
    private function spendOn(App $app): array
    {
        $events = AiUsageEvent::query()->where('app_id', $app->id)->get();

        return [
            'cost' => round((float) $events->sum('cost'), 5),
            'input_tokens' => (int) $events->sum('input_tokens'),
            'output_tokens' => (int) $events->sum('output_tokens'),
            'model' => $events->first()?->model,
            'estimated' => $events->contains(fn (AiUsageEvent $e): bool => (bool) $e->estimated),
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
     * Does the app WORK — not merely validate.
     *
     * A manifest can be perfectly valid and the app still broken. The two worst
     * defects this project has shipped were exactly that: a modal form that
     * saved every child with a null parent while the UI reported success, and a
     * post-save refresh that left the page blank. Both apps would score clean
     * on structure alone, which is why this pass exists.
     *
     * So: write a record through the create path the scaffolder actually wired,
     * with the parameters the page would really pass, then render every page
     * against what was written and see whether it comes back.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{check: string, detail: string}>
     */
    private function smoke(App $app, array $manifest, User $user, ?int &$checksRun = null): array
    {
        $findings = [];
        $checksRun = 0;
        $add = function (string $check, string $detail) use (&$findings): void {
            $findings[] = ['check' => $check, 'detail' => $detail];
        };

        $executor = app(AppActionExecutor::class);
        $created = [];

        // Parents before children, so a child's form has a real parent id to be
        // handed — which is the whole point of the exercise.
        foreach ($this->creationOrder($manifest) as $objectId) {
            $paths = $this->createPathsFor($manifest, $objectId);
            if ($paths === []) {
                $add('no_create_path', $this->objectName($manifest, $objectId).' has no form that creates one');

                continue;
            }

            // EVERY form that creates one, not just the first. A scaffolded app
            // offers two: the "new" modal on the object's own list, where the
            // person picks the parent, and the "add" form on the parent's detail
            // page, which fills the parent in from the URL. Only the second can
            // orphan a record, and exercising only the first is how this pass
            // scored a deliberately broken build clean.
            foreach ($paths as [$page, $form, $action]) {
                $context = [
                    'form' => $this->synthesizeForm($manifest, $form, $created),
                    'params' => $this->pageParamsFrom($page, $manifest, $created),
                ];

                try {
                    $result = $executor->execute($app, $manifest, $action, $context, $user);
                } catch (\Throwable $e) {
                    $add('write_failed', sprintf(
                        '%s from /%s: %s',
                        $this->objectName($manifest, $objectId), $page['slug'], Str::limit($e->getMessage(), 100),
                    ));

                    continue;
                }

                $created[$objectId] ??= $result['record_id'];

                // Every value the action claimed it would write, actually
                // written. A token that resolves to nothing is how children
                // ended up orphaned under a success toast.
                foreach ($action['values'] ?? [] as $slug => $expression) {
                    if (! is_string($expression) || ! str_contains($expression, '{{')) {
                        continue;
                    }
                    // Only assert on what the harness could actually supply. A
                    // many-to-many picker on the FIRST object created has
                    // nothing to point at yet — a person links those later —
                    // and blaming the app for that is measuring the harness.
                    // Params are always supplied, so the orphaned-child case
                    // this pass exists for stays fully covered.
                    if (preg_match('/\{\{\s*form\.([a-z0-9_]+)\s*\}\}/i', $expression, $token) === 1
                        && ($context['form'][$token[1]] ?? null) === null) {
                        continue;
                    }
                    $checksRun++;
                    if (($result['data'][$slug] ?? null) === null) {
                        $add('write_dropped_value', sprintf(
                            '%s.%s saved empty from "%s" on /%s',
                            $this->objectName($manifest, $objectId), $slug, $expression, $page['slug'],
                        ));
                    }
                }
            }
        }

        // Changing one thing must not erase the others. The scaffolded edit
        // form names EVERY field in its values map, so a save that carries only
        // what the person touched would arrive with the rest present-and-blank
        // — the failure that matters most here, because it destroys data the
        // user can see on screen while reporting success.
        foreach ($created as $objectId => $recordId) {
            $edit = $this->editPathFor($manifest, $objectId);
            if ($edit === null) {
                continue;
            }

            [$page, $form, $action] = $edit;
            $before = $this->recordData($app, $recordId);
            $touched = $this->firstEditableSlug($manifest, $form);
            if ($touched === null || $before === []) {
                continue;
            }

            try {
                $after = $executor->execute($app, $manifest, $action, [
                    'form' => [$touched => 'Editado por el banco'],
                    'params' => ['record_id' => $recordId, 'id' => $recordId],
                ], $user)['data'] ?? [];
            } catch (\Throwable $e) {
                $add('edit_failed', sprintf(
                    '%s from /%s: %s',
                    $this->objectName($manifest, $objectId), $page['slug'], Str::limit($e->getMessage(), 100),
                ));

                continue;
            }

            foreach ($before as $slug => $value) {
                if ($slug === $touched || $value === null || $value === '') {
                    continue;
                }
                $checksRun++;
                if (($after[$slug] ?? null) === null) {
                    $add('edit_erased_value', sprintf(
                        '%s.%s was emptied by an edit that only changed %s',
                        $this->objectName($manifest, $objectId), $slug, $touched,
                    ));
                }
            }

            $checksRun++;
            if (($after[$touched] ?? null) !== 'Editado por el banco') {
                $add('edit_not_saved', sprintf(
                    '%s.%s kept its old value after being edited',
                    $this->objectName($manifest, $objectId), $touched,
                ));
            }
        }

        // The buttons on the rows. Whatever the scaffolder wired here is a
        // thing a person will click, and a delete that does not delete is as
        // bad as one that deletes the wrong record.
        foreach ($this->rowActionsIn($manifest) as [$objectId, $action]) {
            $recordId = $created[$objectId] ?? null;
            if ($recordId === null) {
                continue;
            }
            $row = ['id' => $recordId, 'data' => $this->recordData($app, $recordId)];

            try {
                $executor->execute($app, $manifest, $action, ['row' => $row, 'params' => []], $user);
            } catch (\Throwable $e) {
                $add('row_action_failed', sprintf(
                    '%s on %s: %s',
                    $action['type'], $this->objectName($manifest, $objectId), Str::limit($e->getMessage(), 100),
                ));

                continue;
            }

            $checksRun++;
            $gone = $this->recordData($app, $recordId) === [];
            if (($action['type'] === 'delete_record') !== $gone) {
                $add('row_action_wrong_effect', sprintf(
                    '%s on %s %s the record',
                    $action['type'], $this->objectName($manifest, $objectId),
                    $gone ? 'removed' : 'left',
                ));
            }
            if ($gone) {
                unset($created[$objectId]);
            }
        }

        // Now read it all back the way the runtime does.
        $resolver = app(BlockDataResolver::class);
        foreach ($manifest['pages'] ?? [] as $page) {
            $params = $this->pageParamsFrom($page, $manifest, $created);

            try {
                $data = $resolver->resolve($app, $page['blocks'] ?? [], $manifest, ['params' => $params]);
            } catch (\Throwable $e) {
                $add('page_failed', "/{$page['slug']}: ".Str::limit($e->getMessage(), 120));

                continue;
            }

            foreach ($this->dataBlocksIn($page['blocks'] ?? []) as $block) {
                $objectId = $block['object_id'] ?? $block['data_source']['object_id'] ?? null;
                if (! is_string($objectId) || ! isset($created[$objectId])) {
                    continue;
                }
                $payload = $data[$block['id']] ?? null;

                $checksRun++;
                if (($block['type'] ?? null) === 'record_detail') {
                    if (($payload['record'] ?? null) === null) {
                        $add('detail_empty', sprintf(
                            '/%s shows no record for %s, with one written and its id in the params',
                            $page['slug'], $this->objectName($manifest, $objectId),
                        ));
                    }

                    continue;
                }
                if (($payload['rows'] ?? []) === []) {
                    $add('list_empty', sprintf(
                        '/%s lists no %s, with one written',
                        $page['slug'], $this->objectName($manifest, $objectId),
                    ));
                }
            }
        }

        return $findings;
    }

    /**
     * Object ids with every parent ahead of its children, so a child is created
     * when there is something for it to belong to. Cycles keep manifest order —
     * a self-referencing model is not a reason to skip the whole pass.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function creationOrder(array $manifest): array
    {
        $parentsOf = [];
        foreach ($manifest['objects'] as $object) {
            $parentsOf[$object['id']] = collect($object['fields'])
                ->filter(fn (array $f): bool => ($f['type'] ?? null) === 'relation'
                    && ($f['cardinality'] ?? null) === 'many_to_one')
                ->pluck('target_object_id')
                ->filter(fn ($id): bool => is_string($id) && $id !== $object['id'])
                ->all();
        }

        $ordered = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, $parentsOf): void {
            if (in_array($id, $ordered, true) || isset($visiting[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($parentsOf[$id] ?? [] as $parent) {
                $visit($parent);
            }
            unset($visiting[$id]);
            $ordered[] = $id;
        };
        foreach (array_keys($parentsOf) as $id) {
            $visit($id);
        }

        return $ordered;
    }

    /**
     * Every route the app offers for creating one of these: the page, the form,
     * and the create_record it submits.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    private function createPathsFor(array $manifest, string $objectId): array
    {
        $paths = [];
        foreach ($manifest['pages'] ?? [] as $page) {
            foreach ($this->formBlocksIn($page['blocks'] ?? []) as $form) {
                if (($form['object_id'] ?? null) !== $objectId || ($form['mode'] ?? null) !== 'create') {
                    continue;
                }
                foreach ($form['on_submit'] ?? [] as $action) {
                    if (($action['type'] ?? null) === 'create_record' && ($action['object_id'] ?? null) === $objectId) {
                        $paths[] = [$page, $form, $action];
                        break;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * The form the app offers for changing one of these, and the update it
     * submits.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}|null
     */
    private function editPathFor(array $manifest, string $objectId): ?array
    {
        foreach ($manifest['pages'] ?? [] as $page) {
            foreach ($this->formBlocksIn($page['blocks'] ?? []) as $form) {
                if (($form['object_id'] ?? null) !== $objectId || ($form['mode'] ?? null) !== 'edit') {
                    continue;
                }
                foreach ($form['on_submit'] ?? [] as $action) {
                    if (($action['type'] ?? null) === 'update_record' && ($action['object_id'] ?? null) === $objectId) {
                        return [$page, $form, $action];
                    }
                }
            }
        }

        return null;
    }

    /**
     * A field on this form that a person could actually retype. Computed ones
     * are read-only, and a select would need one of its own option values.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $form
     */
    private function firstEditableSlug(array $manifest, array $form): ?string
    {
        $object = collect($manifest['objects'])->firstWhere('id', $form['object_id']);
        $byId = collect($object['fields'] ?? [])->keyBy('id');

        foreach ($form['fields'] ?? [] as $formField) {
            $field = $byId[$formField['field_id']] ?? null;
            if ($field !== null && in_array($field['type'] ?? null, ['string', 'long_text'], true)) {
                return (string) $field['slug'];
            }
        }

        return null;
    }

    /**
     * Every server-side action wired to a button on a table row, with the
     * object whose record it acts on.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function rowActionsIn(array $manifest): array
    {
        $found = [];
        foreach ($manifest['pages'] ?? [] as $page) {
            foreach ($this->flatten($page['blocks'] ?? []) as $block) {
                if (($block['type'] ?? null) !== 'table') {
                    continue;
                }
                foreach ($block['columns'] ?? [] as $column) {
                    foreach ($column['on_click'] ?? [] as $action) {
                        if (! in_array($action['type'] ?? null, ['update_record', 'delete_record'], true)) {
                            continue;
                        }
                        // Only the ones addressing the clicked row: anything
                        // else is not this table's record to act on.
                        if (! str_contains((string) ($action['record_id_expression'] ?? ''), '{{row.id}}')) {
                            continue;
                        }
                        $found[] = [(string) $action['object_id'], $action];
                    }
                }
            }
        }

        return $found;
    }

    /** The record's stored values, or [] when it is gone. */
    private function recordData(App $app, string $recordId): array
    {
        $record = Record::query()
            ->where('app_id', $app->id)
            ->find($recordId);

        return is_array($record?->data) ? $record->data : [];
    }

    /**
     * Values a person would put into this form — one per field it offers, of
     * the shape that field accepts, and for a relation the parent that was
     * created before it, because that is what a person picks.
     *
     * Filling this in matters: leaving relations blank made every child look
     * orphaned and the harness blamed the app for its own omission. A form that
     * genuinely fails to OFFER the relation still shows up, because then there
     * is no field here to fill and the action's token resolves to nothing.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $created
     * @return array<string, mixed>
     */
    private function synthesizeForm(array $manifest, array $form, array $created = []): array
    {
        $object = collect($manifest['objects'])->firstWhere('id', $form['object_id']);
        $byId = collect($object['fields'] ?? [])->keyBy('id');
        $values = [];

        $fields = $form['fields'] ?? collect($form['steps'] ?? [])->flatMap(fn (array $s): array => $s['fields'] ?? [])->all();
        foreach ($fields as $formField) {
            $field = $byId[$formField['field_id']] ?? null;
            if ($field === null) {
                continue;
            }
            $values[$field['slug']] = match ($field['type']) {
                'number', 'rating', 'slider' => 3,
                'currency' => 120.5,
                'boolean' => true,
                'date' => '2026-03-04',
                'datetime' => '2026-03-04T10:00:00',
                'email' => 'benchmark@example.test',
                'url' => 'https://example.test',
                'phone' => '+525555555555',
                'single_select' => $field['options'][0]['value'] ?? null,
                'multi_select' => array_filter([$field['options'][0]['value'] ?? null]),
                // A picker holding one record, or a picker holding many — a
                // person fills both, and leaving the many-sided one blank made
                // the harness blame a film-shoot app for its own omission.
                'relation' => match ($field['cardinality'] ?? null) {
                    'many_to_one' => $created[$field['target_object_id'] ?? ''] ?? null,
                    'many_to_many' => array_filter([$created[$field['target_object_id'] ?? ''] ?? null]),
                    default => null,
                },
                default => 'Benchmark '.$field['slug'],
            };
        }

        return array_filter($values, fn ($v): bool => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $created
     * @return array<string, mixed>
     */
    private function pageParamsFrom(array $page, array $manifest, array $created): array
    {
        $params = [];

        // Read the param name off the block rather than assuming it. The
        // point-of-sale screen addresses its open order as {{params.order}},
        // and a harness that only ever supplied `id` judged it broken for
        // showing the empty state it is designed to show until one is picked.
        foreach ($this->dataBlocksIn($page['blocks'] ?? []) as $block) {
            if (($block['type'] ?? null) !== 'record_detail') {
                continue;
            }
            $id = $created[$block['object_id'] ?? ''] ?? null;
            if ($id === null) {
                continue;
            }
            if (preg_match('/\{\{\s*params\.([a-z0-9_]+)\s*\}\}/i', (string) ($block['record_id_expression'] ?? ''), $m) === 1) {
                $params[$m[1]] = $id;
            }
        }

        // The scaffolder's own detail pages take `id`; a modal's edit form
        // reads `record_id`. Both are the record the page is about.
        if ($params !== [] && ! isset($params['record_id'])) {
            $params['record_id'] = reset($params);
        }

        return $params;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function formBlocksIn(array $blocks): array
    {
        return array_values(array_filter(
            $this->flatten($blocks),
            fn (array $b): bool => in_array($b['type'] ?? null, ['form', 'multi_step_form'], true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function dataBlocksIn(array $blocks): array
    {
        return array_values(array_filter(
            $this->flatten($blocks),
            fn (array $b): bool => in_array($b['type'] ?? null, ['table', 'record_detail'], true),
        ));
    }

    /**
     * Every block in the tree, nesting included — tabs, split layouts, modals.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function flatten(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $out[] = $block;
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                $out = [...$out, ...$this->flatten($block[$key] ?? [])];
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $child) {
                    $out = [...$out, ...$this->flatten($child['blocks'] ?? [])];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $manifest */
    private function objectName(array $manifest, string $objectId): string
    {
        return (string) (collect($manifest['objects'])->firstWhere('id', $objectId)['name'] ?? $objectId);
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
        // "reparto|actores": alternatives that are all right answers. A cast
        // list modelled as "Actor" is not worse than one modelled as
        // "Reparto", and an expectation that fails on a defensible answer
        // measures naming taste rather than correctness.
        if (str_contains($expected, '|')) {
            foreach (explode('|', $expected) as $alternative) {
                if ($this->namedAmong(trim($alternative), $names)) {
                    return true;
                }
            }

            return false;
        }

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
        $cost = collect($results)->sum(fn (array $r): float => (float) ($r['spend']['cost'] ?? 0));
        $estimated = collect($results)->contains(fn (array $r): bool => (bool) ($r['spend']['estimated'] ?? false));
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
                'cost' => round($cost, 4),
                'cost_per_app' => count($results) > 0 ? round($cost / count($results), 4) : 0.0,
                'input_tokens' => collect($results)->sum(fn (array $r): int => (int) ($r['spend']['input_tokens'] ?? 0)),
                'output_tokens' => collect($results)->sum(fn (array $r): int => (int) ($r['spend']['output_tokens'] ?? 0)),
                // True when any provider did not report usage and the ledger had
                // to estimate — the figure above is then not a measurement.
                'cost_estimated' => $estimated,
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
            $this->line(sprintf(
                '  %-22s %-24s %5.1fs  %8s  %3s checks',
                $result['case'],
                $status,
                $result['seconds'],
                isset($result['spend']) ? '$'.number_format($result['spend']['cost'], 4) : '—',
                $result['smoke_checks'] ?? 0,
            ));
            foreach ($result['findings'] as $finding) {
                $this->line("      <fg=yellow>{$finding['check']}</> — ".Str::limit($finding['detail'], 140));
            }
        }

        $t = $report['totals'];
        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%d/%d clean · %d defects · %s per app · %ss · $%s total, $%s per app%s</>',
            $t['clean_apps'], $t['apps'], $t['defects'], $t['defects_per_app'], $t['seconds'],
            number_format($t['cost'], 4), number_format($t['cost_per_app'], 4),
            $t['cost_estimated'] ? ' (estimated)' : '',
        ));
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
