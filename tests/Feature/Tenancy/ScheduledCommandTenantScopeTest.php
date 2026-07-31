<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A console command has no request, so it has no tenant scope: the tenant
 * connection runs as `tenant_app` with no GUC set, RLS fails closed, and every
 * query over a tenant table returns zero rows. The command then succeeds,
 * reports "0 processed", and looks healthy forever while doing nothing.
 *
 * This has now happened twice in this codebase — once in the chatbot analytics
 * aggregation (fixed, and its comment records the same diagnosis) and once in
 * the integration-execution pruner, which ran nightly for a long time deleting
 * nothing. Both were invisible to the test suite by construction: the harness
 * aliases the runtime connections to the owner, so RLS is bypassed and an
 * unscoped command passes its own tests either way.
 *
 * So the invariant is asserted structurally instead. A command that touches
 * tenant data must show one of the two sanctioned strategies:
 *
 *   - scope itself per tenant (TenantScopes / TenantContext), or
 *   - go through the OWNER connection explicitly, which bypasses RLS by design
 *     and forces the author to filter by tenant themselves.
 *
 * Neither is "better"; what is not allowed is neither.
 *
 * Two manual maintenance commands are listed as known and unfixed below. They
 * are NOT scheduled, so their failure is a person running one and seeing "0
 * processed" rather than a silent nightly no-op — and fixing them properly
 * means deciding whether a cross-tenant backfill should iterate tenants or run
 * as the owner, which is a real decision and not a rename. Listed rather than
 * quietly excluded, so the debt is visible in code.
 */
it('never lets a console command touch tenant data without a tenant strategy', function () {
    $tenantModels = collect(File::files(app_path('Models')))
        ->filter(fn ($f): bool => str_contains(File::get($f->getPathname()), 'UsesTenantConnection'))
        ->map(fn ($f): string => $f->getFilenameWithoutExtension())
        ->values();

    expect($tenantModels)->not->toBeEmpty();

    // Known, unfixed, and unscheduled. Shrink this list; never grow it.
    $known = [
        'MigrateKnowledgeBaseDocuments.php',
        'ReindexVectorsCommand.php',
    ];

    $offenders = [];

    foreach (File::allFiles(app_path('Console/Commands')) as $file) {
        if (in_array($file->getFilename(), $known, true)) {
            continue;
        }

        $source = File::get($file->getPathname());

        $touches = $tenantModels
            ->filter(fn (string $model): bool => Str::contains($source, $model.'::'))
            ->values();

        if ($touches->isEmpty()) {
            continue;
        }

        $scoped = Str::contains($source, ['TenantScopes', 'TenantContext', 'runScoped', 'forOwner'])
            // The owner connection bypasses RLS deliberately; a command using it
            // is filtering by tenant in its own SQL.
            || Str::contains($source, "connection('pgsql')");

        if (! $scoped) {
            $offenders[] = $file->getRelativePathname().' → '.$touches->implode(', ');
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These console commands query a tenant model with no tenant strategy.',
        'With no scope RLS returns nothing, so they will silently do nothing:',
        ...$offenders,
        '',
        'Either wrap the work in TenantScopes::each(<platform query>, ...) so it',
        'runs once per tenant, or query through the owner connection and filter',
        'by tenant yourself.',
    ]));
});

it('catches a command that queries tenant data with no strategy', function () {
    // The guard is only worth having if it fails on the shape it exists for.
    $offending = <<<'PHP'
    <?php
    use App\Models\Record;
    class Bad { public function handle() { return Record::query()->delete(); } }
    PHP;

    $tenantModels = collect(File::files(app_path('Models')))
        ->filter(fn ($f): bool => str_contains(File::get($f->getPathname()), 'UsesTenantConnection'))
        ->map(fn ($f): string => $f->getFilenameWithoutExtension());

    $touches = $tenantModels->filter(fn (string $m): bool => Str::contains($offending, $m.'::'));
    $scoped = Str::contains($offending, ['TenantScopes', 'TenantContext', 'runScoped', 'forOwner'])
        || Str::contains($offending, "connection('pgsql')");

    expect($touches)->not->toBeEmpty()
        ->and($scoped)->toBeFalse();
});
