<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EditsTheDraftModel;
use App\Services\Manifest\ManifestEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Link two objects by slug: the relation pair, its inverse, its count rollup and
 * the picker on the child's form and table — none of it addressed by id.
 *
 * `AddDetailPageTool` has told the model to "add a relation first (add_relation)"
 * since it was written. Until now that tool did not exist on this side.
 *
 * See {@see EditsTheDraftModel} for the reasoning.
 */
class AddRelationTool implements Tool
{
    use EditsTheDraftModel;

    public function __construct(
        private ProposeChangeTool $proposeTool,
        private ManifestEditor $editor,
    ) {}

    public function name(): string
    {
        return 'add_relation';
    }

    public function description(): string
    {
        return <<<'DESC'
Link two existing objects, naming both by SLUG. PREFER THIS over propose_change
for any relation: it writes BOTH sides (the picker on the child, the inverse list
on the parent), adds the parent's count rollup, and wires the picker into the
child's create form and table — all of it addressed for you, with no ids to
carry.

`from_slug` is the object that BELONGS TO the other: an order belongs to a
customer, so from_slug=ordenes, to_slug=clientes. Pass kind="many_to_many" for a
symmetric link instead (a picker on each side, no parent/child, no count).

Returns {ok} or {ok:false, errors}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_slug' => $schema->string()
                ->description('Slug of the object that BELONGS TO the other (the many side).')
                ->required(),
            'to_slug' => $schema->string()
                ->description('Slug of the object being pointed AT (the one side).')
                ->required(),
            'name' => $schema->string()
                ->description('Optional label for the link, e.g. «Cliente». Derived from the target object when omitted.'),
            'kind' => $schema->string()
                ->enum(['belongs_to', 'many_to_many'])
                ->description('belongs_to (default) or many_to_many.'),
            'add_to_page' => $schema->boolean()
                ->description('Also wire the picker into the child\'s form + table (default true).'),
        ];
    }

    public function handle(Request $request): string
    {
        $input = $request->all();
        $from = trim((string) ($input['from_slug'] ?? ''));
        $to = trim((string) ($input['to_slug'] ?? ''));

        if ($from === '' || $to === '') {
            return $this->encode($this->failure('`from_slug` and `to_slug` are both required.'));
        }

        $before = $this->proposeTool->currentManifest();
        if (! is_array($before)) {
            return $this->encode($this->failure('No active manifest exists for this app yet.'));
        }

        $slugs = collect($before['objects'] ?? [])->pluck('slug')->filter();
        foreach ([$from, $to] as $slug) {
            if (! $slugs->contains($slug)) {
                return $this->encode($this->failure(
                    "No object with slug '{$slug}' exists. This app has: {$slugs->implode(', ')}."
                ));
            }
        }

        try {
            $after = $this->editor->applyAddRelation(
                $before,
                $from,
                $to,
                isset($input['name']) ? trim((string) $input['name']) : null,
                ($input['add_to_page'] ?? true) !== false,
                (string) ($input['kind'] ?? 'belongs_to'),
            );
        } catch (\Throwable $e) {
            return $this->encode($this->failure($e->getMessage()));
        }

        $result = $this->stackOntoDraft($this->proposeTool, $before, $after, "Relacioné {$from} con {$to}");

        if (($result['ok'] ?? false) === true) {
            $result['message'] = ($input['kind'] ?? 'belongs_to') === 'many_to_many'
                ? "{$from} and {$to} are now linked both ways."
                : "Each {$from} now belongs to a {$to}, and {$to} lists its {$from}.";
        }

        return $this->encode($result);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
