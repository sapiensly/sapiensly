<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Safe-by-design block deletion. The model passes a block_id and we walk the
 * current manifest to find its exact JSON-Pointer location — then we hand a
 * single `remove` op to ProposeChangeTool for the usual validation pipeline.
 *
 * This sidesteps a sharp edge of RFC 6902: if the model reasons about array
 * indices using a stale view of the manifest, a `remove /pages/0/blocks/N`
 * silently deletes whatever block is at index N right now, which is rarely
 * what the user wanted.
 */
class DeleteBlockByIdTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ProposeChangeTool $proposeTool,
    ) {}

    public function name(): string
    {
        return 'delete_block_by_id';
    }

    public function description(): string
    {
        return <<<'DESC'
Remove a block from the manifest by its id. Use this in preference to crafting
a remove patch by hand — it looks up the block's current path in the live
manifest right before submitting, so it cannot accidentally delete a sibling
because of a stale index in your reasoning.

Works on top-level page blocks AND blocks nested inside container/modal/tabs/
accordion/split_view. The resulting proposal is recorded the same way as
propose_change — the platform applies the LAST successful proposal of the turn.

Returns either {ok: true, removed_path, ...} or {ok: false, errors: [...]} if
the block isn't found or the resulting manifest fails validation.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema
                ->string()
                ->description('The id of the block to remove (e.g. blk_01j...).')
                ->required(),
            'change_summary' => $schema
                ->string()
                ->description('One short sentence describing what was removed and why (shown in the audit log).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $blockId = $args['block_id'] ?? '';
        $summary = (string) ($args['change_summary'] ?? '');

        if (! is_string($blockId) || $blockId === '') {
            return json_encode([
                'ok' => false,
                'errors' => [['path' => '/block_id', 'message' => 'block_id is required', 'code' => 'bad_input']],
            ], JSON_THROW_ON_ERROR);
        }

        // Use the in-progress draft when available — covers the case where
        // the user added a block earlier in the turn and now wants it gone
        // before the auto-apply ever lands.
        $manifest = $this->proposeTool->currentManifest();
        if ($manifest === null) {
            return json_encode([
                'ok' => false,
                'errors' => [['path' => '/', 'message' => 'No active manifest exists for this app yet.', 'code' => 'no_manifest']],
            ], JSON_THROW_ON_ERROR);
        }

        $path = $this->findBlockPath($manifest, $blockId);
        if ($path === null) {
            return json_encode([
                'ok' => false,
                'errors' => [[
                    'path' => '/block_id',
                    'message' => "Block id '{$blockId}' was not found in any page.",
                    'code' => 'unresolved_ref',
                ]],
            ], JSON_THROW_ON_ERROR);
        }

        $result = $this->proposeTool->recordProposal(
            [['op' => 'remove', 'path' => $path]],
            $summary,
        );

        if (($result['ok'] ?? false) === true) {
            $result['removed_path'] = $path;
            $result['removed_block_id'] = $blockId;
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * Depth-first scan over every page's block tree, recursing through every
     * container shape (container, modal, tabs, accordion, split_view), to
     * locate the JSON-Pointer path of the block with the given id.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function findBlockPath(array $manifest, string $blockId): ?string
    {
        foreach ($manifest['pages'] ?? [] as $pIdx => $page) {
            $found = $this->scan($page['blocks'] ?? [], "/pages/{$pIdx}/blocks", $blockId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function scan(array $blocks, string $prefix, string $blockId): ?string
    {
        foreach ($blocks as $i => $b) {
            $path = "{$prefix}/{$i}";
            if (($b['id'] ?? null) === $blockId) {
                return $path;
            }

            $type = $b['type'] ?? null;

            if ($type === 'container' || $type === 'modal') {
                $hit = $this->scan($b['blocks'] ?? [], "{$path}/blocks", $blockId);
                if ($hit !== null) {
                    return $hit;
                }
            }

            if ($type === 'tabs') {
                foreach ($b['tabs'] ?? [] as $j => $tab) {
                    $hit = $this->scan($tab['blocks'] ?? [], "{$path}/tabs/{$j}/blocks", $blockId);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }

            if ($type === 'accordion') {
                foreach ($b['sections'] ?? [] as $j => $sec) {
                    $hit = $this->scan($sec['blocks'] ?? [], "{$path}/sections/{$j}/blocks", $blockId);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }

            if ($type === 'split_view') {
                $hit = $this->scan($b['left_blocks'] ?? [], "{$path}/left_blocks", $blockId);
                if ($hit !== null) {
                    return $hit;
                }
                $hit = $this->scan($b['right_blocks'] ?? [], "{$path}/right_blocks", $blockId);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }
}
