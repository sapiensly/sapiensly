<?php

namespace App\Ai\Tools\Builder;

use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationCaller;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Builder power #2. Call a real list/read endpoint through a (power-#1)
 * integration and return the response shape, so the builder can infer the
 * field_map, collection_path and id_path of a connected object before proposing
 * it. This call IS the verification that the mapping is real. Tenant-scoped.
 */
class SampleEndpointTool implements Tool
{
    public function __construct(
        private IntegrationCaller $caller,
        private User $user,
    ) {}

    public function name(): string
    {
        return 'sample_endpoint';
    }

    public function description(): string
    {
        return <<<'DESC'
Fetch a real sample from an external API through a connected integration so you can
design a connected object. Provide integration_id and a path (e.g. "/crm/v3/objects/deals?limit=3").
Optionally pass collection_path if the rows are nested (e.g. "results"). Returns
{ok, status, collection_path, row_keys, sample} — use row_keys to build the
connected object's field_map (external_path), collection_path for the list
operation, and pick an id key for id_path. Read-only; never sends secrets.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()->description('The connected integration id (from create_integration).')->required(),
            'path' => $schema->string()->description('Path appended to the base URL, including any query string.')->required(),
            'collection_path' => $schema->string()->description('Dot path to the row array in the response, if nested (e.g. "results").'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $integration = Integration::query()
            ->forAccountContext($this->user)
            ->find((string) ($args['integration_id'] ?? ''));

        if (! $integration instanceof Integration) {
            return json_encode(['ok' => false, 'error' => 'Integration not found for this tenant.'], JSON_THROW_ON_ERROR);
        }

        try {
            $response = $this->caller->send($integration, 'GET', (string) ($args['path'] ?? ''), actor: $this->user);
        } catch (\Throwable $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        $json = $response->json() ?? [];
        $collectionPath = $args['collection_path'] ?? null;
        $rows = $collectionPath ? (array) Arr::get($json, $collectionPath, []) : (array) $json;
        $first = Arr::first(array_values($rows));

        return json_encode([
            'ok' => $response->successful(),
            'status' => $response->status(),
            'collection_path' => $collectionPath,
            'row_keys' => is_array($first) ? array_keys($first) : [],
            'sample' => Str::limit(json_encode($first), 1000),
        ], JSON_THROW_ON_ERROR);
    }
}
