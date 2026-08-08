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
            ['type' => 'navigate', 'props' => 'to (string URL or relative path; interpolates {{row.*}}/{{params.*}}/{{record.*}} — e.g. to:"/order?id={{record.id}}" right after a create_record opens the new record).'],
            ['type' => 'open_modal', 'props' => 'modal_block_id (must reference a modal block in the same page); optional `params` ({key: value_or_expression}) — values can be expression strings like "{{row.id}}" or literals. Each becomes available inside the modal as {{params.<key>}}. CANONICAL EDIT-FROM-TABLE PATTERN: action column → open_modal {modal_block_id, params:{record_id:"{{row.id}}"}} → modal contains form mode=edit with record_id_expression="{{params.record_id}}".'],
            ['type' => 'close_modal', 'props' => 'modal_block_id (optional — omit to close any open modal)'],
            ['type' => 'create_record', 'props' => 'object_id, values ({field_slug: value_or_expression}). Use {{form.<slug>}} to read submitted form fields, {{params.<X>}} for page params, {{current_user.id}} for the user — but NEVER for a RELATION field, which stores a record id: that write is refused with "No X record matches \'1\'". Fill a relation from the form ({{form.<slug>}}) or by a value the record carries ({{current_user.email}} against the target\'s email field). Values named here OVERRIDE what the form collected, so a values map is not the place to repeat a field the person already filled in. The created record is exposed to LATER actions in the same sequence as {{record.id}} / {{record.data.<slug>}} (e.g. create an order, then navigate to "/pos?order={{record.id}}").'],
            ['type' => 'update_record', 'props' => 'object_id, record_id_expression, values ({field_slug: value_or_expression}). Only sent fields are touched.'],
            ['type' => 'delete_record', 'props' => 'object_id, record_id_expression'],
            ['type' => 'show_toast', 'props' => 'message, level? (info|success|warning|error)'],
            ['type' => 'scan_to_find', 'props' => 'field_id (the field holding the code — usually a string with capture:"barcode"), page_slug (where to open it), param? (query parameter for the id, default "id"). Opens the camera, reads a barcode, finds the ONE record carrying it and navigates there. A closed sheet does nothing; an unknown or ambiguous code says so and stays put. Signed-in runtime only. Use for goods-in, stock checks, asset audits, picking.'],
            ['type' => 'download_pdf', 'props' => 'page_slug (a page of THIS app), params? ({key: value_or_expression}). Downloads that page as a PDF rendered by a real browser, so it looks like the screen rather than a second template that can drift from it. params SCOPE it: {"id": "{{params.id}}"} on a record detail prints THAT record; on a filtered list it prints the rows the reader is looking at. paper? picks the stock: a4 (default) | letter | label_4x6 | label_2x1 | label_dymo — the label sizes print edge to edge, and pair with a `barcode` block to make labels somebody can stick on a box. Use for work orders, quotes, delivery notes and certificates — the person who needs the paper is usually the customer, who does not have the app. Signed-in runtime only (never offered on a public portal).'],
            ['type' => 'refresh', 'props' => 'target_block_id? (optional — reloads the page if omitted)'],
            ['type' => 'share', 'props' => 'EITHER page_slug (+ params?/paper?, exactly like download_pdf) to share that page\'s PDF as a FILE, OR text?/url?/title? to share a message and a link (url defaults to the page the reader is on). Opens the device\'s own share sheet — which is the only route from this app to WhatsApp, and WhatsApp is how the signed delivery note reaches a customer who has no login. Falls back to copying the link where there is no share sheet, and to the plain download when a file cannot be shared. Use it beside download_pdf on anything the customer, not the office, needs.'],
            ['type' => 'copy', 'props' => 'text (interpolates {{row.*}}/{{params.*}}/{{form.*}}). Puts it on the clipboard for pasting into another system — tracking numbers, references, links.'],
            ['type' => 'speak', 'props' => 'text (interpolated), lang? (defaults to the app locale). Reads it out loud, for work done with both hands full: the next address on the route, the quantity to pick. A browser that cannot speak shows the words instead.'],
            ['type' => 'require_identity', 'props' => 'no props. Put it FIRST in the sequence. Asks the device who is holding it — the fingerprint, face or PIN it already knows — and runs nothing else unless it answers. For what somebody should have to mean: a refund, a write-off, a price override, an irreversible delete. Verified server-side against a challenge; never queued offline; a device with no sensor stops the sequence and says so, so do not gate an action people must be able to take on any machine. Signed-in runtime only.'],
            ['type' => 'toggle_fullscreen', 'props' => 'no props. Enters or leaves full screen. For an app on a tablet bolted to a counter, where the browser chrome above it is somebody\'s escape hatch into the rest of the internet.'],
        ];

        return json_encode([
            'actions' => $this->withSchema('action', $catalog),
            'patterns' => [
                'create_via_modal' => 'button[on_click: open_modal] → modal containing form[on_submit: create_record, close_modal, show_toast, refresh]',
                'inline_create' => 'form on a page directly; on_submit: create_record, show_toast, refresh',
                'delete_row' => 'table row buttons → button[on_click: delete_record, refresh] with confirm dialog',
                'inline_toggle_in_table' => 'action column → on_click:[update_record {object_id, record_id_expression:"{{row.id}}", values:{<bool_slug>:true}}, refresh]. Used for "Marcar completada" in a tasks/todos list.',
                'edit_via_modal_from_table' => 'action column → on_click:[open_modal {modal_block_id, params:{record_id:"{{row.id}}"}}] → modal contains form[mode:"edit", record_id_expression:"{{params.record_id}}", on_submit:[update_record, close_modal, refresh]]. The modal\'s edit form picks up the row id from the params injected by open_modal.',
                'open_new_record' => 'button → on_click:[create_record {object_id, values:{…}}, navigate {to:"/<detail_page>?id={{record.id}}"}]. Creates the record then opens its detail page as the current context — {{record.id}} is the just-created id.',
                'pos_add_to_cart' => 'A POS screen: page param ?order=<id> is the open order (set via open_new_record). A card_grid of products with on_click:[create_record {object_id:<line>, values:{<order_rel>:"{{params.order}}", <product_rel>:"{{row.id}}", cantidad:1}}, refresh] adds the tapped product as a line. The cart is a table over the line object filtered by {{params.order}} with −/+ action columns (update_record cantidad) + delete; the order total is a rollup sum the cart shows.',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
