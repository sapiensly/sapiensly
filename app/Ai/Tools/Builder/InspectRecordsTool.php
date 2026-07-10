<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lets Claude peek at the actual records under an object so it can ground its
 * proposals on real data shape. Returns the total count plus up to N sample
 * rows. Useful before proposing a filter ("does this field even exist on most
 * rows?") or aggregation ("are the values numeric?").
 *
 * Privacy: ships keys + raw values from the JSONB blob. For MVP we assume the
 * tenant trusts the model with their own data; tighten via a stricter
 * Anonymizer step in a later phase if needed.
 */
class InspectRecordsTool implements Tool
{
    private const MAX_LIMIT = 5;

    public function __construct(private App $appModel) {}

    public function name(): string
    {
        return 'inspect_records';
    }

    public function description(): string
    {
        return 'Inspect the actual records stored under one object so you can ground your patch on real data shape. Returns {object_id, total_count, sample_rows: [{id, data}]}. Call this before proposing a filter or aggregation when you need to see which keys are present and what type the values have.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema
                ->string()
                ->description('The id of the object_definition (e.g. obj_01j...) whose records to inspect.')
                ->required(),
            'limit' => $schema
                ->integer()
                ->description('How many sample rows to return (max '.self::MAX_LIMIT.'). Defaults to 3.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = (string) ($args['object_id'] ?? '');
        $limit = (int) ($args['limit'] ?? 3);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        if ($objectId === '') {
            return json_encode(['error' => 'object_id is required'], JSON_THROW_ON_ERROR);
        }

        // A CONNECTED object stores nothing in the records table — reading it
        // there always says "0 records", and the agent then mis-diagnosed a
        // WORKING live source as broken ("no data, reconnect or load a demo
        // snapshot?") minutes after the dashboard rendered real rows. Route
        // through the shared query service, which reads connected objects live.
        $manifest = app(AppManifestService::class)->getActiveManifest($this->appModel);
        $object = collect($manifest['objects'] ?? [])->firstWhere('id', $objectId);
        if (is_array($object) && (($object['source']['type'] ?? null) === 'connected')) {
            try {
                $result = app(RecordQueryService::class)
                    ->queryWithMeta($this->appModel, ['object_id' => $objectId, 'limit' => $limit], $manifest);
            } catch (\Throwable $e) {
                return json_encode([
                    'object_id' => $objectId,
                    'source' => 'connected',
                    'error' => 'The live source could not be read: '.$e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            }

            return json_encode([
                'object_id' => $objectId,
                'source' => 'connected (live rows — this object stores nothing locally)',
                'total_count' => $result['total'],
                'sample_rows' => $result['records']->map(fn (Record $r) => ['id' => $r->id, 'data' => $r->data])->all(),
            ], JSON_THROW_ON_ERROR);
        }

        $scope = Record::query()
            ->where('app_id', $this->appModel->id)
            ->where('object_definition_id', $objectId);

        $total = (clone $scope)->count();
        $rows = (clone $scope)
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'data'])
            ->map(fn (Record $r) => ['id' => $r->id, 'data' => $r->data])
            ->all();

        return json_encode([
            'object_id' => $objectId,
            'total_count' => $total,
            'sample_rows' => $rows,
        ], JSON_THROW_ON_ERROR);
    }
}
