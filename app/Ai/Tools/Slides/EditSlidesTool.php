<?php

namespace App\Ai\Tools\Slides;

use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckEditor;
use App\Services\Slides\DeckValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The Slide Builder assistant's editing hand: applies slide operations to the
 * deck being edited, atomically (whole-deck revalidation; on error nothing is
 * saved and the message names the exact slide + field to fix).
 *
 * The tool records each successfully applied manifest on the instance so the
 * turn runner can broadcast the fresh deck to the UI when the stream ends.
 */
class EditSlidesTool implements Tool
{
    /** @var array<string, mixed>|null the manifest after the last successful apply */
    public ?array $appliedManifest = null;

    public function __construct(
        private readonly Document $deck,
        private readonly DeckEditor $editor,
        private readonly ?User $actor = null,
    ) {}

    public function name(): string
    {
        return 'edit_slides';
    }

    public function description(): string
    {
        return 'Apply slide operations to the deck being edited, in order: replace {index, slide}, insert {index, slide} (BEFORE index; index = slide_count appends), remove {index}, move {index, to}. Indexes are 0-based against the deck state AFTER the previous operation. You can also rename the deck (name) or switch its theme ('.implode('|', DeckValidator::THEMES).'). The whole deck is revalidated — on error NOTHING is saved and the message tells you exactly what to fix. The user sees the updated preview immediately after a successful call.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operations' => $schema->array()->description('Slide operations applied in order, each {op: replace|insert|remove|move, index, slide?, to?}. `slide` is a full slide object {layout, ...fields}.'),
            'name' => $schema->string()->description('Optional new deck title.'),
            'theme' => $schema->string()->enum(DeckValidator::THEMES)->description('Optional new theme.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $manifest = json_decode((string) $this->deck->refresh()->body, true);
        if (! is_array($manifest)) {
            return json_encode(['error' => 'The deck manifest is corrupted.'], JSON_THROW_ON_ERROR);
        }

        $operations = $args['operations'] ?? [];
        if (! is_array($operations)) {
            return json_encode(['error' => 'operations must be an array.'], JSON_THROW_ON_ERROR);
        }

        $name = is_string($args['name'] ?? null) && trim($args['name']) !== '' ? trim($args['name']) : null;
        $theme = is_string($args['theme'] ?? null) && $args['theme'] !== '' ? $args['theme'] : null;

        if ($operations === [] && $name === null && $theme === null) {
            return json_encode(['error' => 'Nothing to apply — pass operations, name or theme.'], JSON_THROW_ON_ERROR);
        }

        [$next, $error] = $this->editor->apply($manifest, array_values($operations), $name, $theme);
        if ($next === null) {
            return json_encode(['error' => $error], JSON_THROW_ON_ERROR);
        }

        $this->editor->persist($this->deck, $next, $this->actor);
        $this->appliedManifest = $next;

        return json_encode([
            'applied' => true,
            'slide_count' => count((array) $next['slides']),
            'theme' => $next['theme'],
            'name' => $next['title'],
        ], JSON_THROW_ON_ERROR);
    }
}
