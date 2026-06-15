<?php

namespace App\Services\Connected;

use App\Models\Integration;
use App\Services\Integrations\IntegrationCaller;
use Illuminate\Support\Arr;

/**
 * Runtime read path for connected objects (builder power #2). Given a manifest
 * object whose `source` is `connected`, it lists rows live from the external
 * system through the integration and maps them to the object's fields via
 * `field_map` — partial-tolerant. Passthrough: it stores NOTHING in our database.
 * Provider-agnostic; reads are remote/may-fail and degrade to an error result
 * rather than throwing.
 */
class ConnectedObjectReader
{
    public function __construct(private readonly IntegrationCaller $caller) {}

    /**
     * @param  array<string, mixed>  $object  a manifest object_definition with source.type === 'connected'
     * @param  array<string, mixed>  $options  query?
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    public function list(array $object, Integration $integration, array $options = []): array
    {
        $source = $object['source'] ?? [];
        $op = $source['operations']['list'] ?? null;

        if (! is_array($op) || empty($op['path'])) {
            return ['ok' => false, 'rows' => [], 'error' => 'No list operation is configured for this object.'];
        }

        try {
            $response = $this->caller->send(
                $integration,
                (string) ($op['method'] ?? 'GET'),
                (string) $op['path'],
                ['query' => (array) ($options['query'] ?? [])],
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'rows' => [], 'error' => "External system returned HTTP {$response->status()}."];
        }

        $json = $response->json() ?? [];
        $collectionPath = $op['collection_path'] ?? null;
        $raw = $collectionPath
            ? (array) Arr::get($json, $collectionPath, [])
            : (array) $json;

        $fieldSlugById = collect($object['fields'] ?? [])->pluck('slug', 'id')->all();
        $rows = array_map(
            fn ($row) => $this->mapRow((array) $row, $source, $fieldSlugById),
            array_values($raw),
        );

        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * Map one external row to manifest field slugs, plus the external id under
     * `_external_id` for later read/write addressing. Partial-tolerant.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $fieldSlugById
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $source, array $fieldSlugById): array
    {
        $mapped = [];
        foreach (($source['field_map'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['field_id']) || ! isset($entry['external_path'])) {
                continue;
            }
            $slug = $fieldSlugById[$entry['field_id']] ?? $entry['field_id'];
            $mapped[$slug] = Arr::get($row, $entry['external_path']);
        }

        if (! empty($source['id_path'])) {
            $mapped['_external_id'] = Arr::get($row, $source['id_path']);
        }

        return $mapped;
    }
}
