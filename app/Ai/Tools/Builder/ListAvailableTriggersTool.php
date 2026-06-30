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
            ['type' => 'channel.message_received', 'props' => 'channel_id (required, a WhatsApp/widget channel in your org), contains? (case-insensitive substring the message text must contain) — fires when an inbound message arrives on that channel. Payload: {channel: {id, type, name}, message: {text, content_type}, contact: {id, name, identifier}, conversation_id}'],
            ['type' => 'integration.event', 'props' => 'integration_id (required, an http integration with a webhook signing secret set), event? (case-insensitive event-type filter, e.g. "pull_request" or "invoice.paid") — fires when the provider POSTs to the integration\'s signed webhook URL. Payload: {integration_id, event, body, headers}'],
            ['type' => 'integration.poll', 'props' => 'tool_id (required, a connected rest_api/graphql tool returning a list), watermark_path (required, a monotonic field per item e.g. "id" or "created_at"), items_path? (dot-path to the array; omit if the response is the array), interval_minutes? (default 15) — polls on a schedule and fires once per newly-seen item. Payload: {tool_id, item, watermark}'],
            ['type' => 'email.inbound', 'props' => 'integration_id (required, an integration with inbound email configured — provider + signing secret/token), to_contains? / subject_contains? (case-insensitive filters) — fires when a provider POSTs a parsed email to the integration\'s email webhook URL. Payload: {integration_id, email: {from, from_name, to, subject, text, html, message_id}}'],
        ];

        return json_encode([
            'triggers' => $this->withSchema('trigger', $catalog),
            'context_tokens' => [
                '{{trigger.<path>}}' => 'access trigger payload — e.g. {{trigger.record.data.nombre}}',
                '{{vars.<X>}}' => 'workflow-scoped variable set by a set_variable step or output_variable',
                '{{steps.<step_id>.output.<X>}}' => 'output of a previous step in the same workflow',
            ],
            // Rules an AI must follow to author a trigger that actually fires —
            // the schema can't express these, so they cause silent no-ops or
            // save errors if ignored.
            'notes' => [
                'one_trigger' => 'A workflow has exactly ONE trigger (workflow.trigger is a single object, not a list).',
                'resolve_ids' => 'NEVER invent the ids a trigger references — resolve real ones first. object_id/field_id: from this manifest\'s objects[].fields[]. integration_id (integration.event, email.inbound): call list_integrations. tool_id (integration.poll): call list_tools or list_connector_actions and pick an ACTIVE rest_api/graphql tool that returns a list. channel_id (channel.message_received): a WhatsApp/widget channel in the org.',
                'external_setup' => 'Some triggers need setup the manifest cannot do, or they never fire: integration.event & email.inbound require the Integration to have an inbound webhook signing secret (or token) set under Integrations → Inbound webhook, and the provider must POST to that integration\'s signed URL (shown on the integration). email.inbound also reads the provider preset from the integration (postmark/mailgun/sendgrid/generic). integration.poll requires tool_id to be an active connected tool.',
                'needs_scheduler' => 'Time-based triggers (schedule, record.date_reached) and integration.poll only fire when the host runs `php artisan schedule:run` every minute. They do nothing without it.',
                'trigger_filter' => 'record.* and record.date_reached `filter` is a filter_expression: and/or/not groups + leaf {op, field_id, value}; ops eq/neq/gt/gte/lt/lte/in/not_in/contains/starts_with/ends_with/is_null/is_not_null/between. In a TRIGGER filter use a literal `value` only — `value_expression` and relation traversal (`related`) are rejected; do relation checks inside the workflow with a record.query + branch step.',
                'date_reached_field' => 'record.date_reached `field_id` MUST be a date or datetime field on the object.',
                'fire_once' => 'record.*, channel.message_received, integration.event, email.inbound and integration.poll fire per event/item (once each). webhook/event/email/poll dedupe provider retries automatically; date_reached/schedule fire on a monotonic cursor — no manual idempotency needed.',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
