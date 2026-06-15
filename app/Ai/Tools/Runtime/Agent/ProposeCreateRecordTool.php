<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Ai\Tools\Runtime\Agent\Concerns\DescribesProposedWrite;
use App\Services\Runtime\ProposedActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent write tool (builder power #3, gated). Records a PROPOSAL to
 * create a record — it never executes. The proposal surfaces as an action card
 * the user approves; only then does the existing write path run it
 * (propose-don't-mutate, Rule 2). Scoped to the objects the agent may write.
 */
class ProposeCreateRecordTool implements Tool
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
        return 'propose_create_record';
    }

    public function description(): string
    {
        return <<<'DESC'
Propose creating a new record. This does NOT create it — it prepares the change
for the user to approve. Use describe_capabilities for object and field ids; pass
`values` keyed by field slug. Tell the user you've prepared it for their approval;
do not claim it's done.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()->description('The object to create a record in.')->required(),
            'values' => $schema->object()->description('Field values keyed by field slug.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = $args['object_id'] ?? null;
        $values = $args['values'] ?? [];

        if (! is_string($objectId) || ! in_array($objectId, $this->writableObjectIds, true)) {
            return json_encode(['ok' => false, 'error' => 'This object is not available for writing.'], JSON_THROW_ON_ERROR);
        }
        if (! is_array($values) || $values === []) {
            return json_encode(['ok' => false, 'error' => 'values must be a non-empty object keyed by field slug.'], JSON_THROW_ON_ERROR);
        }

        $object = $this->findObject($this->manifest, $objectId) ?? [];
        $preview = 'Create '.$this->objectName($object).': '.$this->describeValues($object, $values);

        $this->proposals->add(['type' => 'create_record', 'object_id' => $objectId, 'values' => $values], $preview);

        return json_encode([
            'ok' => true,
            'preview' => $preview,
            'message' => 'Proposed — awaiting the user\'s approval. Not yet created.',
        ], JSON_THROW_ON_ERROR);
    }
}
