<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EnrichesCatalogEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Closed catalog of workflow step types. Anything outside this list will
 * fail manifest validation — the engine won't dispatch handlers it doesn't know.
 */
class ListAvailableStepsTool implements Tool
{
    use EnrichesCatalogEntries;

    public function name(): string
    {
        return 'list_available_steps';
    }

    public function description(): string
    {
        return 'List every step type allowed inside workflow.steps. Each entry includes a prose summary, output shape, plus `params` (required/optional args + allowed enum values), an `example` skeleton, and the `definition` name. Use this before composing a workflow.';
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
                'type' => 'record.aggregate',
                'props' => 'object_id, aggregation (count|sum|avg|min|max|distinct_count|median|p90|p95), field_id? (required for everything but count), filter?',
                'output' => '{value, aggregation}',
                'note' => 'The cross-record math primitive: reduce a field over a filtered set in ONE step (no manual foreach/script.run loop). Canonical pattern for "keep a parent total in sync": on a child record.updated, record.aggregate the children (filter by the parent link, e.g. {op:eq, field_id:<parent rel>, value_expression:"{{trigger.record.data.<parent slug>}}"}) with sum over the amount field, then record.update the parent with {{steps.<id>.output.value}}. Or aggregate then branch to flag a threshold (spend > budget). The result is {{steps.<id>.output.value}}.',
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
                'note' => 'A bare LLM call (your prompt, your model). For a configured persona with its own brand voice, knowledge base and tools, use agent.invoke instead.',
            ],
            [
                'type' => 'agent.invoke',
                'props' => 'agent_id (a real id from list_agents), message (the input/prompt, expression-resolved)',
                'output' => '{text, agent_id, knowledge_bases} — the reply is {{steps.<id>.output.text}}',
                'note' => 'Run a CONFIGURED agent: its own model, instructions, knowledge bases (RAG) and tools all apply — unlike ai.complete, which is a raw prompt. Use for "let the CMO/support/research agent produce X". Call list_agents first for a real agent_id.',
            ],
            [
                'type' => 'http.request',
                'props' => 'method (GET|POST|PUT|PATCH|DELETE), url, headers?, query?, body?, timeout_seconds?',
                'output' => '{status, body, headers}',
                'note' => 'The escape hatch for one-off, unconfigured endpoints. PREFER connector.call against a configured integration when one exists or can be provisioned.',
            ],
            [
                'type' => 'connector.call',
                'props' => 'tool_id (a connector action id from list_connector_actions), inputs? ({input_name: expression})',
                'output' => '{data, effect, status} — typed outputs live under data, addressable via {{steps.<id>.output.data.…}}',
                'note' => 'Invoke a typed, authenticated connector action on a configured integration (REST/GraphQL/database). Call list_available_integrations then list_connector_actions first to get real ids, typed inputs and the read/write effect. This is the first-class way to touch an external system — prefer it over a hand-rolled http.request.',
            ],
            [
                'type' => 'script.run',
                'props' => 'code (JavaScript, max 20000 chars), input? ({name: expression}), timeout_ms? (50-10000, default 2000)',
                'output' => 'whatever the script returns (JSON-serialisable); a scalar is wrapped as {value}',
                'note' => 'Runs in an isolated QuickJS sandbox with NO network, filesystem or host access (no require/import/fetch/process). The resolved `input` map is the `input` argument; use a top-level `return` for the output. Use ONLY for procedural logic the expression language and other steps cannot express (loops, building/transforming arrays or objects). Prefer set_variable/branch/record.* for everything else.',
            ],
        ];

        return json_encode([
            'steps' => $this->withSchema('step', $catalog),
            'shared_step_props' => 'Every step also accepts: id (stp_<ulid>), name?, output_variable? (assigns the step output to {{vars.<X>}}), skip_if? (expression, truthy skips the step), retry?',
        ], JSON_THROW_ON_ERROR);
    }
}
