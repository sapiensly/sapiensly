<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Ai\Tools\Runtime\Agent\Concerns\DescribesProposedWrite;
use App\Services\Runtime\ProposedActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent write tool (builder power #3, gated). Records a PROPOSAL to
 * update an existing record by id — it never executes. The user approves the
 * action card; only then does the existing write path run it (Rule 2). Works for
 * internal and connected objects, scoped to the agent's write grant.
 */
class ProposeUpdateRecordTool implements Tool
{
    use DescribesProposedWrite;

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $writableObjectIds
     */
    public function __construct(
        private array $manifest,
        private array $writableObjectIds,
        private ProposedActions $proposals,
    ) {}

    public function name(): string
    {
        return 'propose_update_record';
    }

    public function description(): string
    {
        return <<<'DESC'
Propose updating an existing record. This does NOT change it — it prepares the
change for the user to approve. Pass the record's id (from query_object) and only
the `values` you want to change, keyed by field slug. Tell the user you've
prepared it for approval; do not claim it's done.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()->description('The object the record belongs to.')->required(),
            'record_id' => $schema->string()->description('The id of the record to update (from query_object).')->required(),
            'values' => $schema->object()->description('Changed field values keyed by field slug.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = $args['object_id'] ?? null;
        $recordId = $args['record_id'] ?? null;
        $values = $args['values'] ?? [];

        if (! is_string($objectId) || ! in_array($objectId, $this->writableObjectIds, true)) {
            return json_encode(['ok' => false, 'error' => 'This object is not available for writing.'], JSON_THROW_ON_ERROR);
        }
        if (! is_string($recordId) || $recordId === '') {
            return json_encode(['ok' => false, 'error' => 'record_id is required.'], JSON_THROW_ON_ERROR);
        }
        if (! is_array($values) || $values === []) {
            return json_encode(['ok' => false, 'error' => 'values must be a non-empty object keyed by field slug.'], JSON_THROW_ON_ERROR);
        }

        $object = $this->findObject($this->manifest, $objectId) ?? [];
        $preview = 'Update '.$this->objectName($object)." ({$recordId}): ".$this->describeValues($object, $values);

        $this->proposals->add([
            'type' => 'update_record',
            'object_id' => $objectId,
            'record_id_expression' => $recordId,
            'values' => $values,
        ], $preview);

        return json_encode([
            'ok' => true,
            'preview' => $preview,
            'message' => 'Proposed — awaiting the user\'s approval. Not yet changed.',
        ], JSON_THROW_ON_ERROR);
    }
}
