<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Ai\Tools\Runtime\Agent\Concerns\DescribesProposedWrite;
use App\Services\Runtime\ProposedActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent write tool (builder power #3, gated). Records a PROPOSAL to
 * delete a record — it never executes; the user approves first (Rule 2). Only
 * internal objects support delete (a connected object's source has no delete
 * operation, per power #2), so a connected delete is refused at propose time.
 */
class ProposeDeleteRecordTool implements Tool
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
        return 'propose_delete_record';
    }

    public function description(): string
    {
        return <<<'DESC'
Propose deleting a record by id. This does NOT delete it — it prepares the
deletion for the user to approve (Rule 2). Only available for internal objects.
Tell the user you've prepared it for approval; do not claim it's done.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()->description('The object the record belongs to.')->required(),
            'record_id' => $schema->string()->description('The id of the record to delete.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = $args['object_id'] ?? null;
        $recordId = $args['record_id'] ?? null;

        if (! is_string($objectId) || ! in_array($objectId, $this->writableObjectIds, true)) {
            return json_encode(['ok' => false, 'error' => 'This object is not available for writing.'], JSON_THROW_ON_ERROR);
        }
        if (! is_string($recordId) || $recordId === '') {
            return json_encode(['ok' => false, 'error' => 'record_id is required.'], JSON_THROW_ON_ERROR);
        }

        $object = $this->findObject($this->manifest, $objectId) ?? [];
        if (($object['source']['type'] ?? 'internal') === 'connected') {
            return json_encode(['ok' => false, 'error' => 'Deleting connected records is not supported.'], JSON_THROW_ON_ERROR);
        }

        $preview = 'Delete '.$this->objectName($object)." ({$recordId})";

        $this->proposals->add([
            'type' => 'delete_record',
            'object_id' => $objectId,
            'record_id_expression' => $recordId,
        ], $preview);

        return json_encode([
            'ok' => true,
            'preview' => $preview,
            'message' => 'Proposed — awaiting the user\'s approval. Not yet deleted.',
        ], JSON_THROW_ON_ERROR);
    }
}
