<?php

namespace App\Ai\Tools\Builder\Concerns;

use App\Ai\Tools\Builder\ProposeChangeTool;

/**
 * The plumbing behind the builder's typed data-model edits.
 *
 * `ManifestEditor` computes these edits already, and the MCP server has exposed
 * them as add_field / add_object / add_relation since they were written — but
 * the BUILDER, the one model that writes these patches dozens of times per app,
 * only ever had raw propose_change. It therefore hand-enumerated every
 * `field_id` into every form, table and record_detail that had to show the new
 * field.
 *
 * That enumeration is where builds break. Measured across four runs of one
 * brief: one invented id sprayed across twelve pointers in a single call, and
 * `unresolved_ref` the largest rejection class in the whole ledger. Telling the
 * model to write slugs instead did not work — twice, once through the tool
 * description and once through the error text. So the work is removed rather
 * than taught: these tools take an object SLUG and a field NAME, and there is
 * no id for the model to get wrong.
 *
 * The edit is computed against the TURN'S RUNNING DRAFT and stacked onto it as
 * ops, never written as its own version — the builder's unit of work is the
 * turn, and a tool that persisted on its own would split one turn across two
 * versions and break undo.
 */
trait EditsTheDraftModel
{
    /**
     * Stack a computed manifest onto the running draft.
     *
     * The ops are coarse (`replace` of the top-level keys that changed) because
     * the edit is computed whole by ManifestEditor; expressing it as fine-grained
     * ops would mean re-deriving the diff, which is the very thing this exists to
     * avoid. It is safe because the new manifest was computed FROM the draft
     * these ops are applied to.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function stackOntoDraft(ProposeChangeTool $proposeTool, array $before, array $after, string $summary): array
    {
        $ops = [];
        foreach (['objects', 'pages', 'permissions'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $ops[] = ['op' => 'replace', 'path' => '/'.$key, 'value' => $after[$key] ?? []];
            }
        }

        if ($ops === []) {
            return ['ok' => true, 'message' => 'Nothing changed.'];
        }

        return $proposeTool->recordProposal($ops, $summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return [
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ];
    }
}
