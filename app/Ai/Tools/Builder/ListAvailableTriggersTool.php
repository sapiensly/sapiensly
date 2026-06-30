<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EnrichesCatalogEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Closed catalog of workflow triggers Claude is allowed to propose. The
 * engine refuses unknown trigger types, so listing them up-front saves the
 * model from inventing things like 'cron' or 'pubsub' that don't exist yet.
 */
class ListAvailableTriggersTool implements Tool
{
    use EnrichesCatalogEntries;

    public function name(): string
    {
        return 'list_available_triggers';
    }

    public function description(): string
    {
        return 'List the trigger types you may use inside workflow.trigger. Each entry includes a prose summary plus `params` (required/optional args + allowed enum values), an `example` skeleton, and the `definition` name. Call this before proposing a workflow.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $catalog = [
            ['type' => 'manual', 'props' => 'label? — fired by a run_workflow action from a button'],
            ['type' => 'record.created', 'props' => 'object_id (required), filter? — fires after a record of that object is created. Trigger payload: {record: {id, data, object_definition_id, ...}}'],
            ['type' => 'record.updated', 'props' => 'object_id (required), filter? — fires after a record is updated. Payload also includes `before` snapshot and `changed` array of field slugs.'],
            ['type' => 'record.deleted', 'props' => 'object_id (required), filter? — fires after a record is deleted. Payload carries the final record snapshot.'],
            ['type' => 'schedule', 'props' => 'cron (required, 5-field e.g. "0 9 * * 1-5"), timezone? (IANA, default UTC) — fires on a recurring schedule. Trigger payload: {scheduled_at: ISO8601}'],
            ['type' => 'webhook.inbound', 'props' => 'dedupe_path? (JSON path to the provider delivery id, e.g. "id"), signature_header? (default X-Sapiensly-Signature) — fires when an external system POSTs to the workflow\'s signed webhook URL. Trigger payload: {webhook: {body, headers}}'],
            ['type' => 'record.date_reached', 'props' => 'object_id (required), field_id (required, a date/datetime field), offset? ({value, unit: minutes|hours|days|weeks, direction: before|after}), at? (HH:MM, default 09:00, for date-only fields), timezone? (IANA, default UTC), filter? — fires when the record\'s date field ± offset reaches now (e.g. 3 days before due_date). Payload: {record: {...}, reached_at: ISO8601}'],
        ];

        return json_encode([
            'triggers' => $this->withSchema('trigger', $catalog),
            'context_tokens' => [
                '{{trigger.<path>}}' => 'access trigger payload — e.g. {{trigger.record.data.nombre}}',
                '{{vars.<X>}}' => 'workflow-scoped variable set by a set_variable step or output_variable',
                '{{steps.<step_id>.output.<X>}}' => 'output of a previous step in the same workflow',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
