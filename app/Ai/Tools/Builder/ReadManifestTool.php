<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Reads the App's manifest for the builder. By DEFAULT it returns a compact
 * STRUCTURAL SUMMARY (ids/slugs/types only — no property values), because the
 * full manifest can be tens of thousands of tokens and the model rarely needs
 * all of it. Pass `expand` with an object/page/workflow id to get that one
 * element's full subtree. This is the single biggest builder token saver.
 *
 * State-aware: if ProposeChangeTool has recorded successful patches THIS TURN,
 * it reads the in-progress DRAFT (what the model thinks the manifest looks like
 * now); otherwise the persisted "active" manifest — so the model doesn't fight
 * itself re-proposing changes it already made.
 */
class ReadManifestTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'read_manifest';
    }

    public function description(): string
    {
        return <<<'DESC'
Read the App's manifest. Returns `{state, op_count, note, summary}` by default —
a COMPACT structure (objects→fields with id/slug/type, pages→blocks with id/type,
workflows, settings keys, agent on/off). Use this first to see the shape.

To edit a specific object/page/workflow, call again with `expand: "<id>"` to get
that ONE element's FULL definition (returned as `element`). Do NOT expand the
whole manifest element-by-element; only expand what you're about to change.

- `state` is "draft" if you've made successful `propose_change` calls THIS TURN
  (the summary/element reflect your in-progress draft) — do NOT re-propose what's
  already there; it persists automatically at turn end. Else "active" (published).
- Every object has implicit system fields sys_created_at / sys_updated_at (datetime).
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expand' => $schema->string()
                ->description('Optional id of an object/page/workflow to return in full instead of the summary.'),
        ];
    }

    public function handle(Request $request): string
    {
        $draft = $this->proposeTool?->runningDraft();
        $isDraft = $draft !== null;
        $manifest = $draft ?? $this->manifestService->getActiveManifest($this->appModel) ?? [];

        $envelope = [
            'state' => $isDraft ? 'draft' : 'active',
            'op_count' => $isDraft ? ($this->proposeTool?->opCount() ?? 0) : 0,
            'note' => $isDraft
                ? 'In-progress draft (your successful propose_change ops this turn are already applied; do not re-propose them).'
                : 'Published manifest. You have NOT proposed any change this turn yet (0 ops drafted). state:"active" means nothing is drafted — it does NOT mean an edit you described is saved. To change anything you must still call propose_change; if it returns ok:true, state flips to "draft".',
        ];

        $expand = (string) ($request->all()['expand'] ?? '');
        if ($expand !== '') {
            $element = $this->findElement($manifest, $expand);
            $envelope['expanded'] = $expand;
            $envelope['element'] = $element ?? new \stdClass;
            if ($element === null) {
                $envelope['note'] = "No object/page/workflow with id '{$expand}' was found. Check the summary for valid ids.";
            }

            return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        $envelope['summary'] = $this->summarize($manifest);

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Compact structural view: ids/slugs/types only, no property values.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function summarize(array $manifest): array
    {
        return [
            'name' => $manifest['name'] ?? null,
            'slug' => $manifest['slug'] ?? null,
            'objects' => array_map(fn (array $o) => [
                'id' => $o['id'] ?? null,
                'slug' => $o['slug'] ?? null,
                'name' => $o['name'] ?? null,
                'source' => $o['source']['type'] ?? 'internal',
                'fields' => array_map(fn (array $f) => [
                    'id' => $f['id'] ?? null,
                    'slug' => $f['slug'] ?? null,
                    'type' => $f['type'] ?? null,
                ], $o['fields'] ?? []),
            ], $manifest['objects'] ?? []),
            'pages' => array_map(fn (array $p) => [
                'id' => $p['id'] ?? null,
                'slug' => $p['slug'] ?? null,
                'name' => $p['name'] ?? null,
                'blocks' => array_map(fn (array $b) => [
                    'id' => $b['id'] ?? null,
                    'type' => $b['type'] ?? null,
                ], $p['blocks'] ?? []),
            ], $manifest['pages'] ?? []),
            'workflows' => array_map(fn (array $w) => [
                'id' => $w['id'] ?? null,
                'slug' => $w['slug'] ?? null,
                'name' => $w['name'] ?? null,
                'trigger' => $w['trigger']['type'] ?? null,
            ], $manifest['workflows'] ?? []),
            'settings_keys' => array_keys($manifest['settings'] ?? []),
            'agent_enabled' => (bool) ($manifest['agent']['enabled'] ?? false),
        ];
    }

    /**
     * Find an object/page/workflow by id for full expansion.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findElement(array $manifest, string $id): ?array
    {
        foreach (['objects', 'pages', 'workflows'] as $collection) {
            foreach ($manifest[$collection] ?? [] as $element) {
                if (($element['id'] ?? null) === $id) {
                    return $element;
                }
            }
        }

        return null;
    }
}
