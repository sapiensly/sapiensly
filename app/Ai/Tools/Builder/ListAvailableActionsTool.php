<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EnrichesCatalogEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Closed catalog of actions that can appear inside an action_sequence
 * (button.on_click, form.on_submit, form.on_cancel). Anything outside this
 * list will fail manifest validation.
 */
class ListAvailableActionsTool implements Tool
{
    use EnrichesCatalogEntries;

    public function name(): string
    {
        return 'list_available_actions';
    }

    public function description(): string
    {
        return 'List every action type allowed inside action_sequence (button.on_click, form.on_submit). Each entry includes a prose summary plus `params` (required/optional args + allowed enum values), an `example` skeleton, and the `definition` name. Use this before composing a button or form on_submit.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $catalog = [
            ['type' => 'navigate', 'props' => 'to (string URL or relative path)'],
            ['type' => 'open_modal', 'props' => 'modal_block_id (must reference a modal block in the same page); optional `params` ({key: value_or_expression}) — values can be expression strings like "{{row.id}}" or literals. Each becomes available inside the modal as {{params.<key>}}. CANONICAL EDIT-FROM-TABLE PATTERN: action column → open_modal {modal_block_id, params:{record_id:"{{row.id}}"}} → modal contains form mode=edit with record_id_expression="{{params.record_id}}".'],
            ['type' => 'close_modal', 'props' => 'modal_block_id (optional — omit to close any open modal)'],
            ['type' => 'create_record', 'props' => 'object_id, values ({field_slug: value_or_expression}). Use {{form.<slug>}} to read submitted form fields, {{params.<X>}} for page params, {{current_user.id}} for the user.'],
            ['type' => 'update_record', 'props' => 'object_id, record_id_expression, values ({field_slug: value_or_expression}). Only sent fields are touched.'],
            ['type' => 'delete_record', 'props' => 'object_id, record_id_expression'],
            ['type' => 'show_toast', 'props' => 'message, level? (info|success|warning|error)'],
            ['type' => 'refresh', 'props' => 'target_block_id? (optional — reloads the page if omitted)'],
        ];

        return json_encode([
            'actions' => $this->withSchema('action', $catalog),
            'patterns' => [
                'create_via_modal' => 'button[on_click: open_modal] → modal containing form[on_submit: create_record, close_modal, show_toast, refresh]',
                'inline_create' => 'form on a page directly; on_submit: create_record, show_toast, refresh',
                'delete_row' => 'table row buttons → button[on_click: delete_record, refresh] with confirm dialog',
                'inline_toggle_in_table' => 'action column → on_click:[update_record {object_id, record_id_expression:"{{row.id}}", values:{<bool_slug>:true}}, refresh]. Used for "Marcar completada" in a tasks/todos list.',
                'edit_via_modal_from_table' => 'action column → on_click:[open_modal {modal_block_id, params:{record_id:"{{row.id}}"}}] → modal contains form[mode:"edit", record_id_expression:"{{params.record_id}}", on_submit:[update_record, close_modal, refresh]]. The modal\'s edit form picks up the row id from the params injected by open_modal.',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
