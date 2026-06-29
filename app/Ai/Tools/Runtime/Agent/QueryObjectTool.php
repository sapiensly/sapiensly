<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Models\App;
use App\Services\Records\BlockDataResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent read tool (builder power #3). Lists rows of an object the agent
 * is granted to read, source-agnostic: it routes through BlockDataResolver, so
 * internal records and connected objects (power #2) read through the same seam
 * and return the same {id, data} shape. Reads are remote/may-fail for connected
 * objects — a failure degrades to a tool error the agent can report, never a
 * crash. The object_id is validated against the agent's read grant, so the tool
 * cannot reach an object the manifest did not expose.
 */
class QueryObjectTool implements Tool
{
    /** Hard cap on rows returned to the model, regardless of the requested limit. */
    private const MAX_ROWS = 50;

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $readableObjectIds
     * @param  array<string, mixed>  $context  carries __access so reads honour row_filter + hidden fields
     */
    public function __construct(
        private App $appModel,
        private array $manifest,
        private array $readableObjectIds,
        private BlockDataResolver $blockData,
        private array $context = [],
    ) {}

    public function name(): string
    {
        return 'query_object';
    }

    public function description(): string
    {
        return <<<'DESC'
List rows of a data object this assistant can read. Call describe_capabilities
first to learn the object ids and field ids. Returns { count, total, has_more,
rows: [{ id, data: { field_slug: value } }] } (capped at 50 rows; total is the
full match count for paging, null for connected objects). filter/sort use the
same shape as the app's data blocks. Pass expand: [relation_field_id] to resolve
belongs_to relations inline — each row then carries expanded: { [field_id]: { id,
data } | null }, sparing a second lookup. Works the same for internal and
connected (external) objects.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()
                ->description('The object to list (from describe_capabilities).')
                ->required(),
            'filter' => $schema->object()
                ->description('Optional filter_expression: {op, ...} (eq/neq/gt/and/or/not). Relation traversal: {op: related, field_id: <relation field>, condition: <filter on the related object>}.'),
            'search' => $schema->string()
                ->description('Optional free-text search across the object\'s text fields (case-insensitive).'),
            'expand' => $schema->array()
                ->description('Optional belongs_to relation field ids to resolve inline; each row gains expanded: { [field_id]: { id, data } | null }.'),
            'sort' => $schema->array()
                ->description('Optional [{field_id, direction: asc|desc}].'),
            'limit' => $schema->integer()
                ->description('Max rows to return (capped at 50).'),
            'offset' => $schema->integer()
                ->description('Rows to skip, for paging.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = $args['object_id'] ?? null;

        if (! is_string($objectId) || ! in_array($objectId, $this->readableObjectIds, true)) {
            return json_encode([
                'error' => 'This object is not available to this assistant.',
            ], JSON_THROW_ON_ERROR);
        }

        $dataSource = ['object_id' => $objectId];
        foreach (['filter', 'search', 'sort', 'offset', 'expand'] as $key) {
            if (isset($args[$key])) {
                $dataSource[$key] = $args[$key];
            }
        }
        $dataSource['limit'] = min((int) ($args['limit'] ?? self::MAX_ROWS), self::MAX_ROWS);

        try {
            $rows = $this->blockData->queryObject($this->appModel, $dataSource, $this->manifest, $this->context);
            $total = $this->blockData->countObject($this->appModel, $dataSource, $this->manifest, $this->context);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $offset = (int) ($args['offset'] ?? 0);

        return json_encode([
            'count' => count($rows),
            'total' => $total,
            // For a connected object (total null) we can't know the true total —
            // a full page suggests more may follow.
            'has_more' => $total !== null
                ? ($offset + count($rows)) < $total
                : count($rows) >= self::MAX_ROWS,
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR);
    }
}
