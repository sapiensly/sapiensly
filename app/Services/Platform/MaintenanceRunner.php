<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Runs a FIXED set of maintenance commands, and nothing else.
 *
 * This is the one place in the suite that executes application commands, so the
 * design is deliberately unclever: an allowlist of literal command strings with
 * no arguments accepted from the caller. There is no passthrough, no "just add
 * a flag", no interpolation of anything a model produced — the operation name
 * either matches an entry here or it does not run. Every escape hatch that has
 * ever been added to something like this is how it became a remote shell.
 *
 * Destructive database commands (`migrate`, `db:wipe`, `queue:flush`) are
 * absent on purpose; the only migration entry is a dry run that reports what
 * WOULD happen.
 */
class MaintenanceRunner
{
    /**
     * operation => [artisan command, human description, whether it disrupts running work]
     *
     * @var array<string, array{command: string, description: string, disruptive: bool}>
     */
    public const OPERATIONS = [
        'cache:clear' => [
            'command' => 'cache:clear',
            'description' => 'Flush the application cache.',
            'disruptive' => false,
        ],
        'config:clear' => [
            'command' => 'config:clear',
            'description' => 'Drop the cached config so .env changes take effect.',
            'disruptive' => false,
        ],
        'config:cache' => [
            'command' => 'config:cache',
            'description' => 'Rebuild the cached config.',
            'disruptive' => false,
        ],
        'route:clear' => [
            'command' => 'route:clear',
            'description' => 'Drop the cached route table.',
            'disruptive' => false,
        ],
        'view:clear' => [
            'command' => 'view:clear',
            'description' => 'Drop compiled Blade views.',
            'disruptive' => false,
        ],
        'storage:link' => [
            'command' => 'storage:link',
            'description' => 'Recreate the public storage symlink.',
            'disruptive' => false,
        ],
        'queue:restart' => [
            'command' => 'queue:restart',
            'description' => 'Ask workers to finish the current job and exit.',
            'disruptive' => true,
        ],
        'horizon:terminate' => [
            'command' => 'horizon:terminate',
            'description' => 'Gracefully restart Horizon so it picks up new code.',
            'disruptive' => true,
        ],
        'horizon:snapshot' => [
            'command' => 'horizon:snapshot',
            'description' => 'Record a queue metrics snapshot.',
            'disruptive' => false,
        ],
        'migrate:status' => [
            'command' => 'migrate:status',
            'description' => 'List migrations and whether each has run.',
            'disruptive' => false,
        ],
        'migrate:pretend' => [
            'command' => 'migrate --pretend --database=pgsql',
            'description' => 'Show the SQL pending migrations WOULD run. Changes nothing.',
            'disruptive' => false,
        ],
    ];

    /**
     * @return array{ok: bool, operation: string, exit_code: ?int, output: string, error: ?string}
     */
    public function run(string $operation): array
    {
        $definition = self::OPERATIONS[$operation] ?? null;

        if ($definition === null) {
            return [
                'ok' => false,
                'operation' => $operation,
                'exit_code' => null,
                'output' => '',
                'error' => 'Unknown maintenance operation. Allowed: '.implode(', ', array_keys(self::OPERATIONS)),
            ];
        }

        try {
            $exitCode = Artisan::call($definition['command']);

            return [
                'ok' => $exitCode === 0,
                'operation' => $operation,
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'operation' => $operation,
                'exit_code' => null,
                'output' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return list<array{operation: string, description: string, disruptive: bool}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (self::OPERATIONS as $operation => $definition) {
            $catalog[] = [
                'operation' => $operation,
                'description' => $definition['description'],
                'disruptive' => $definition['disruptive'],
            ];
        }

        return $catalog;
    }
}
