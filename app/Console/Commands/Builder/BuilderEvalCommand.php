<?php

namespace App\Console\Commands\Builder;

use App\Enums\Visibility;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Builder model-fitness eval. Runs the REAL builder loop (BuilderAiService::
 * streamMessage — the only entry point with a model override + usage recording +
 * proposal apply) over a fixed sequence of build tasks, once per model, and
 * scores each on: tool-loop completion, RFC-6902 patch validity, resulting
 * manifest validity, per-task structural assertions (scope), cost, and cache
 * hit-rate. Tasks run as a multi-turn conversation on ONE app per model so the
 * turn-2+ prompt cache is actually exercised (a fresh app per task would never
 * show cache reads).
 *
 * This HITS PAID PROVIDER APIS and must run in an environment that has the
 * provider credentials + catalog (i.e. NOT the empty local dev DB). Pass a
 * --user whose org has OpenRouter configured. Throwaway apps are deleted unless
 * --keep is given.
 */
#[Signature('builder:eval
    {--model=* : Model id(s) to test (repeatable). Default: deepseek/deepseek-v4-pro + claude-opus-4-8 baseline}
    {--user= : User id or email to act as (sets the tenant scope). Required.}
    {--keep : Keep the throwaway apps instead of deleting them}
    {--yes : Skip the paid-API confirmation prompt}')]
#[Description('Eval whether a model survives the real builder tool-use loop (patch validity, manifest validity, scope, cost, cache).')]
class BuilderEvalCommand extends Command
{
    /**
     * Sequential build tasks. They run in order on one app, each turn building on
     * the last, so the cumulative manifest grows and the cache warms. `check`
     * asserts the cumulative manifest after the turn — the scope/correctness gate.
     *
     * @var list<array{prompt: string, check: callable(array): bool}>
     */
    private function tasks(): array
    {
        return [
            [
                'prompt' => "Create an object called 'task' with a text field 'title' and a boolean field 'done'.",
                'check' => fn (array $m) => $this->hasField($m, 'task', 'title') && $this->hasField($m, 'task', 'done'),
            ],
            [
                'prompt' => "Add a date field 'due_date' to the task object.",
                'check' => fn (array $m) => $this->hasField($m, 'task', 'due_date'),
            ],
            [
                'prompt' => "Create an object 'project' with a text field 'name', and give each task a relation to a project.",
                'check' => fn (array $m) => $this->hasField($m, 'project', 'name') && $this->findObject($m, 'task') !== null,
            ],
            [
                'prompt' => 'Add a full list page for tasks (heading, a new-task form, and a table of tasks).',
                'check' => fn (array $m) => $this->mentions($m, 'task') && $this->hasPage($m),
            ],
        ];
    }

    public function handle(
        BuilderAiService $builder,
        AppManifestService $manifests,
        ManifestValidator $validator,
        TenantContext $tenant,
    ): int {
        $models = $this->option('model') ?: ['deepseek/deepseek-v4-pro', 'claude-opus-4-8'];

        $userRef = (string) $this->option('user');
        if ($userRef === '') {
            $this->error('--user is required (id or email) to set the tenant scope.');

            return self::FAILURE;
        }

        $user = User::query()
            ->when(is_numeric($userRef), fn ($q) => $q->where('id', (int) $userRef), fn ($q) => $q->where('email', $userRef))
            ->first();

        if ($user === null) {
            $this->error("No user matches '{$userRef}'.");

            return self::FAILURE;
        }

        // Establish the tenant scope for the whole run (mirrors the queue's
        // EstablishTenantContext::fromOwner — no HTTP middleware in CLI).
        $tenant->set($user->organization_id, $user->id);

        // Silence WebSocket broadcasts: streamMessage emits stream chunks over
        // Reverb, which we neither need nor want to depend on in CLI.
        config(['broadcasting.default' => 'null']);

        $this->info('Builder eval');
        $this->line('  user:   '.$user->email.' (org '.$user->organization_id.')');
        $this->line('  models: '.implode(', ', $models));
        $this->line('  tasks:  '.count($this->tasks()).' sequential turns per model');
        $this->warn('This calls PAID provider APIs (one full builder conversation per model).');

        if (! $this->option('yes') && ! $this->confirm('Proceed?', false)) {
            return self::SUCCESS;
        }

        $summary = [];

        foreach ($models as $model) {
            $this->newLine();
            $this->info("=== {$model} ===");
            $summary[$model] = $this->runModel($model, $user, $builder, $manifests, $validator);
        }

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * Run the full task sequence for one model on a fresh throwaway app.
     *
     * @return array<string, mixed>
     */
    private function runModel(
        string $model,
        User $user,
        BuilderAiService $builder,
        AppManifestService $manifests,
        ManifestValidator $validator,
    ): array {
        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => 'eval-'.Str::lower(Str::random(10)),
            'name' => 'Builder Eval',
            'description' => 'Throwaway app created by builder:eval',
            'visibility' => Visibility::Private,
        ]);
        $manifests->createVersion($app, $manifests->initialManifest($app), $user, 'Initial version');

        $conversation = $builder->startConversation($app, $user);

        $turns = [];

        foreach ($this->tasks() as $i => $task) {
            $placeholder = BuilderMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => '',
                'status' => 'none',
            ]);

            $lastUsageId = (int) (AiUsageEvent::query()->max('id') ?? 0);
            $startedAt = microtime(true);
            $error = null;

            try {
                $builder->streamMessage($placeholder, $task['prompt'], null, null, $model);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $placeholder->refresh();
            $elapsed = round(microtime(true) - $startedAt, 1);

            // The turn's usage rows (>1 only if failover fired). Sum the spend;
            // the served model is whichever row actually produced output.
            $usageRows = AiUsageEvent::query()
                ->where('id', '>', $lastUsageId)
                ->where('module', 'builder')
                ->orderBy('id')
                ->get();

            $input = (int) $usageRows->sum('input_tokens');
            $cacheRead = (int) $usageRows->sum('cache_read_tokens');
            $cacheWrite = (int) $usageRows->sum('cache_write_tokens');
            $output = (int) $usageRows->sum('output_tokens');
            $cost = (float) $usageRows->sum('cost');
            $servedModel = $usageRows->last()->model ?? null;
            $fellBack = $servedModel !== null && $servedModel !== $model;

            $manifest = $manifests->getActiveManifest($app) ?? [];
            $manifestValid = $validator->validate($manifest)->valid;
            // status 'applied'/'pending' means propose_change captured a patch
            // that passed the tool's server-side apply (RFC-6902 + requirements).
            $patchValid = in_array($placeholder->status, ['applied', 'pending'], true)
                && filled($placeholder->proposed_patch);
            $assertPass = $error === null && ($task['check'])($manifest);

            $totalIn = $input + $cacheRead + $cacheWrite;
            $cachePct = $totalIn > 0 ? round(100 * $cacheRead / $totalIn, 1) : 0.0;

            $turns[] = compact(
                'error', 'elapsed', 'servedModel', 'fellBack', 'input', 'cacheRead',
                'cacheWrite', 'output', 'cost', 'manifestValid', 'patchValid', 'assertPass', 'cachePct'
            ) + ['summary' => $placeholder->change_summary];

            $flag = $assertPass ? '<info>PASS</info>' : '<error>FAIL</error>';
            $fb = $fellBack ? " <comment>[fell back to {$servedModel}]</comment>" : '';
            $this->line(sprintf(
                '  turn %d %s  %ss  patch:%s manifest:%s  cache:%s%%  $%.4f%s',
                $i + 1,
                $flag,
                $elapsed,
                $patchValid ? 'ok' : 'no',
                $manifestValid ? 'ok' : 'no',
                $cachePct,
                $cost,
                $fb
            ));
            if ($error !== null) {
                $this->line('       <error>error: '.Str::limit($error, 160).'</error>');
            }
        }

        if (! $this->option('keep')) {
            $app->delete();
        } else {
            $this->line('  kept app: '.$app->slug);
        }

        $pass = collect($turns)->where('assertPass', true)->count();
        $totalCost = collect($turns)->sum('cost');
        $totalIn = collect($turns)->sum(fn ($t) => $t['input'] + $t['cacheRead'] + $t['cacheWrite']);
        $totalCacheRead = collect($turns)->sum('cacheRead');

        return [
            'turns' => $turns,
            'pass' => $pass,
            'total' => count($turns),
            'patch_ok' => collect($turns)->where('patchValid', true)->count(),
            'manifest_ok' => collect($turns)->where('manifestValid', true)->count(),
            'fell_back' => collect($turns)->where('fellBack', true)->count(),
            'cost' => $totalCost,
            'cost_per_pass' => $pass > 0 ? $totalCost / $pass : null,
            'cache_pct' => $totalIn > 0 ? round(100 * $totalCacheRead / $totalIn, 1) : 0.0,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->info('=== Scorecard ===');

        $rows = [];
        foreach ($summary as $model => $s) {
            $rows[] = [
                $model,
                "{$s['pass']}/{$s['total']}",
                "{$s['patch_ok']}/{$s['total']}",
                "{$s['manifest_ok']}/{$s['total']}",
                $s['fell_back'],
                $s['cache_pct'].'%',
                '$'.number_format($s['cost'], 4),
                $s['cost_per_pass'] !== null ? '$'.number_format($s['cost_per_pass'], 4) : '—',
            ];
        }

        $this->table(
            ['model', 'tasks pass', 'patch ok', 'manifest ok', 'fell back', 'cache', 'total $', '$/pass'],
            $rows,
        );
        $this->line('Scope/correctness ("tasks pass") is a structural heuristic; eyeball each turn\'s change summary for over-reach.');
    }

    // --- manifest assertions (defensive: tolerant of exact schema nesting) ---

    /** Find the first object node whose slug/name slugifies to $slug. */
    private function findObject(array $manifest, string $slug): ?array
    {
        return $this->search($manifest, fn (array $n) => $this->slugMatches($n, $slug)
            && (isset($n['fields']) || ($n['type'] ?? null) === 'object'));
    }

    private function hasField(array $manifest, string $object, string $field): bool
    {
        $obj = $this->findObject($manifest, $object);

        return $obj !== null && $this->search($obj, fn (array $n) => $this->slugMatches($n, $field)) !== null;
    }

    private function hasPage(array $manifest): bool
    {
        return $this->search($manifest, fn (array $n) => isset($n['blocks']) || ($n['type'] ?? null) === 'page') !== null;
    }

    private function mentions(array $manifest, string $needle): bool
    {
        return stripos((string) json_encode($manifest), $needle) !== false;
    }

    private function slugMatches(array $node, string $slug): bool
    {
        foreach (['slug', 'name', 'key', 'id'] as $k) {
            if (isset($node[$k]) && is_string($node[$k]) && Str::slug($node[$k]) === Str::slug($slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Depth-first search for the first array node matching $predicate.
     *
     * @param  callable(array): bool  $predicate
     */
    private function search(array $node, callable $predicate): ?array
    {
        if (array_is_list($node) === false && $predicate($node)) {
            return $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->search($value, $predicate);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
