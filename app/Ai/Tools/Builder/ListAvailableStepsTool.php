<?php

namespace App\Ai\Tools\Builder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Closed catalog of workflow step types. Anything outside this list will
 * fail manifest validation — the engine won't dispatch handlers it doesn't know.
 */
class ListAvailableStepsTool implements Tool
{
    public function name(): string
    {
        return 'list_available_steps';
    }

    public function description(): string
    {
        return 'List every step type allowed inside workflow.steps. Each entry includes required args and output shape. Use this before composing a workflow.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $catalog = [
            [
                'type' => 'log',
                'props' => 'message (string with expressions), level? (debug|info|warning|error)',
                'output' => '{logged: true, message}',
            ],
            [
                'type' => 'set_variable',
                'props' => 'variable (lowercase_snake), value (expression or literal)',
                'output' => '{variable, value}',
            ],
            [
                'type' => 'record.create',
                'props' => 'object_id, values ({field_slug: expression})',
                'output' => '{record_id, data}',
            ],
            [
                'type' => 'record.update',
                'props' => 'object_id, record_id_expression, values',
                'output' => '{record_id, data}',
            ],
            [
                'type' => 'record.delete',
                'props' => 'object_id, record_id_expression',
                'output' => '{record_id}',
            ],
            [
                'type' => 'record.query',
                'props' => 'object_id, filter?, limit?',
                'output' => '{count, rows: [{id, data}]}',
            ],
            [
                'type' => 'branch',
                'props' => 'cases ([{condition, steps}]), default_steps?',
                'output' => '{matched: case_index | "default" | null}',
                'note' => 'A condition is a full boolean expression: "{{vars.total}} > 1000", "{{trigger.record.data.estado}} != \"x\" && {{vars.activo}}". Operators == != < <= > >=, and/or/not (or && || !), ternary. Use ~/concat for strings, not +.',
            ],
            [
                'type' => 'foreach',
                'props' => 'items (expression resolving to an array, e.g. {{steps.calc.output.primes}}), item_variable? (default "item"), index_variable?, steps[] (run once per element)',
                'output' => '{iterations, truncated}',
                'note' => 'The fan-out primitive: run sub-steps once per array element. Inside, reference the current element via {{vars.<item_variable>}}. Canonical pattern for "compute a list, then store one record each": a script.run returns the list, then a foreach wraps a record.create using {{vars.item}}. Capped at 1000 iterations.',
            ],
            [
                'type' => 'ai.complete',
                'props' => 'model? (claude-sonnet-4-..., etc), system_prompt?, user_prompt, max_tokens?, temperature?',
                'output' => '{text, model}',
            ],
            [
                'type' => 'http.request',
                'props' => 'method (GET|POST|PUT|PATCH|DELETE), url, headers?, query?, body?, timeout_seconds?',
                'output' => '{status, body, headers}',
            ],
            [
                'type' => 'script.run',
                'props' => 'code (JavaScript, max 20000 chars), input? ({name: expression}), timeout_ms? (50-10000, default 2000)',
                'output' => 'whatever the script returns (JSON-serialisable); a scalar is wrapped as {value}',
                'note' => 'Runs in an isolated QuickJS sandbox with NO network, filesystem or host access (no require/import/fetch/process). The resolved `input` map is the `input` argument; use a top-level `return` for the output. Use ONLY for procedural logic the expression language and other steps cannot express (loops, building/transforming arrays or objects). Prefer set_variable/branch/record.* for everything else.',
            ],
        ];

        return json_encode([
            'steps' => $catalog,
            'shared_step_props' => 'Every step also accepts: id (stp_<ulid>), name?, output_variable? (assigns the step output to {{vars.<X>}}), skip_if? (expression, truthy skips the step), retry?',
        ], JSON_THROW_ON_ERROR);
    }
}
