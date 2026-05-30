<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Returns the App's manifest as JSON so Claude can reason about the existing
 * structure before proposing edits.
 *
 * State-aware: if ProposeChangeTool has already recorded successful patches
 * in this turn, we return the IN-PROGRESS DRAFT (i.e. what Claude *thinks*
 * the manifest currently looks like). Otherwise we return the persisted
 * "active" manifest.
 *
 * Why this matters: without this, Claude would propose an op, then call
 * read_manifest, see the unchanged persisted manifest, conclude "my
 * propose_change failed silently", and try to add the same thing again
 * — which then DOES fail because the running draft validation refuses the
 * duplicate. We were watching the model fight itself in a loop.
 */
class ReadManifestTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        /**
         * Optional companion that holds the running draft for the current
         * turn. Nullable so the tool still works in tests and contexts
         * (e.g. background analysis jobs) where no propose tool is wired.
         */
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'read_manifest';
    }

    public function description(): string
    {
        return <<<'DESC'
Read the App's manifest as a JSON envelope `{state, note, op_count, manifest}`.

- `state` is "draft" if you have already made successful `propose_change` calls THIS TURN, in which case `manifest` is the in-progress draft your subsequent propose_change will validate against.
- `state` is "active" if no proposals have been recorded yet — `manifest` is the persisted, currently-published version.
- `op_count` is the number of ops your turn has stacked so far (0 when state="active").

Every object also has two implicit system fields you can reference without declaring them: sys_created_at and sys_updated_at (both datetime). See list_available_field_types for details.

IMPORTANT: when state="draft", do NOT re-propose changes you already made — they are present in the manifest you're reading, just not yet persisted. They WILL persist automatically when your turn ends.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $draft = $this->proposeTool?->runningDraft();
        if ($draft !== null) {
            return json_encode([
                'state' => 'draft',
                'op_count' => $this->proposeTool?->opCount() ?? 0,
                'note' => 'This is your in-progress draft, with every successful propose_change op from THIS turn already applied. Any further propose_change call will validate against this state. The draft will be persisted automatically when your turn ends — do not re-propose what you already added.',
                'manifest' => $draft,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        $manifest = $this->manifestService->getActiveManifest($this->appModel);

        return json_encode([
            'state' => 'active',
            'op_count' => 0,
            'note' => 'No proposals recorded yet in this turn. This is the currently-published manifest.',
            'manifest' => $manifest ?? new \stdClass,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
