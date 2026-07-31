<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Platform\MaintenanceRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Run one maintenance operation from a fixed allowlist and return its output. Call with no arguments to see the catalog. Available: cache:clear, config:clear, config:cache, route:clear, view:clear, storage:link, queue:restart, horizon:terminate, horizon:snapshot, migrate:status, migrate:pretend (dry run — prints the SQL pending migrations WOULD run and changes nothing). There is no passthrough and no arguments: an operation either matches an allowlist entry exactly or it does not run, so this can never become a general command runner. Operations marked disruptive (queue:restart, horizon:terminate) stop in-flight workers — confirm with the user before running one on production while builds or imports are in progress. Real migrations, db:wipe and queue:flush are deliberately absent. Audited.')]
class RunPlatformMaintenanceTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'operation' => ['sometimes', 'nullable', 'string', 'max:60'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $runner = app(MaintenanceRunner::class);
        $operation = $validated['operation'] ?? null;

        if ($operation === null || $operation === '') {
            return Response::json([
                'operations' => $runner->catalog(),
                'note' => 'Pass `operation` to run one. Operations marked disruptive also need confirm: true.',
            ]);
        }

        $definition = MaintenanceRunner::OPERATIONS[$operation] ?? null;

        if ($definition === null) {
            return Response::error(
                "Unknown operation '{$operation}'. Allowed: ".implode(', ', array_keys(MaintenanceRunner::OPERATIONS))
            );
        }

        // A second, explicit step for the ones that interrupt running work —
        // a model reaching for "restart the queue" on a hunch should not be
        // able to kill an in-flight build on the first call.
        if ($definition['disruptive'] && ! ($validated['confirm'] ?? false)) {
            return Response::error(
                "'{$operation}' interrupts running workers: ".$definition['description'].' '
                .'Re-run with confirm: true once the user has agreed.'
            );
        }

        $result = $runner->run($operation);

        $this->audit(
            actor: $actor,
            summary: "Ran maintenance '{$operation}' — ".($result['ok'] ? 'ok' : 'FAILED'),
            meta: [
                'operation' => $operation,
                'exit_code' => $result['exit_code'],
                'disruptive' => $definition['disruptive'],
            ],
            targetType: 'maintenance',
            targetLabel: $operation,
            result: $result['ok'] ? PlatformAuditLog::RESULT_OK : PlatformAuditLog::RESULT_FAILED,
        );

        return Response::json([
            'operation' => $operation,
            'description' => $definition['description'],
            'ok' => $result['ok'],
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
            'error' => $result['error'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->description('The allowlisted operation to run. Omit to list what is available.'),
            'confirm' => $schema->boolean()->description('Required for disruptive operations (queue:restart, horizon:terminate).'),
        ];
    }
}
