<?php

/**
 * `hasRole('sysadmin')` must never come back.
 *
 * Spatie scopes the roles relation to the current permissions TEAM
 * (`organization_id`), while the sysadmin role is assigned globally with a null
 * team. Every MCP request pins an organization and SetPermissionsTeam does the
 * same on the web, so the check returns false for any sysadmin who belongs to
 * one — silently. It is the worst shape a bug can take: it reads correctly, it
 * passes review, and it only misbehaves for the exact people it governs.
 *
 * `User::isSysAdmin()` is the answer, and this guard keeps the old spelling from
 * drifting back in through a copy-paste.
 */
function phpSourceFiles(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('never checks the sysadmin role through the team-scoped spatie helper', function () {
    $offenders = [];

    foreach (phpSourceFiles(dirname(__DIR__, 3).'/app') as $path) {
        foreach (file($path) as $number => $line) {
            // Skip prose: the doc comments that explain WHY this is banned
            // naturally have to name the thing they are banning.
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                continue;
            }

            if (preg_match('/hasRole\(\s*[\'"]sysadmin[\'"]\s*\)/', $line) === 1) {
                $offenders[] = str_replace(dirname(__DIR__, 3).'/', '', $path).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Use $user->isSysAdmin() instead of hasRole(\'sysadmin\') at:',
        ...$offenders,
    ]));
});

it('gates the admin area on the global check rather than the spatie role middleware', function () {
    $routes = file_get_contents(dirname(__DIR__, 3).'/routes/admin.php');

    expect($routes)->toContain("'sysadmin'")
        ->and($routes)->not->toContain('role:sysadmin');
});
