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
     * @param  array<string, mixed>  $query  the block's data-source query (filter/sort/limit/offset), pushed
     *                                       down to the external API's params where the list operation declares
     *                                       the mapping — unmapped capabilities degrade gracefully (no-op).
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    public function list(array $object, Integration $integration, array $query = []): array
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
                ['query' => $this->buildExternalQuery($op, $source, $query)],
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
     * Translate the block's data-source query into the external API's query
     * params, driven entirely by the list operation's declared mappings — the
     * names are manifest data, never per-provider code. Anything the operation
     * doesn't declare a mapping for (e.g. a non-equality filter, an unmapped
     * sort field) is simply not pushed down: the read degrades gracefully
     * rather than failing. Passthrough is preserved — we only shape the request.
     *
     * @param  array<string, mixed>  $op  the list source_operation
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $query  data-source query (filter/sort/limit/offset)
     * @return array<string, mixed>
     */
    private function buildExternalQuery(array $op, array $source, array $query): array
    {
        $params = [];

        $limit = $query['limit'] ?? null;
        $offset = $query['offset'] ?? null;

        if (! empty($op['page_size_param']) && $limit !== null) {
            $params[$op['page_size_param']] = (int) $limit;
        }

        if (! empty($op['page_param']) && $offset !== null) {
            $params[$op['page_param']] = ($op['page_mode'] ?? 'offset') === 'page'
                ? intdiv((int) $offset, max((int) ($limit ?? 1), 1)) + 1
                : (int) $offset;
        }

        $sort = $query['sort'][0] ?? null;
        if (is_array($sort) && ! empty($op['sort_param']) && ! empty($sort['field_id'])) {
            $externalName = $this->externalPathFor($source, (string) $sort['field_id']);
            if ($externalName !== null) {
                $params[$op['sort_param']] = $externalName;
                if (! empty($op['order_param'])) {
                    $params[$op['order_param']] = ($sort['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                }
            }
        }

        $filterParams = [];
        foreach ($op['filter_params'] ?? [] as $fp) {
            if (is_array($fp) && ! empty($fp['field_id']) && ! empty($fp['param'])) {
                $filterParams[$fp['field_id']] = $fp['param'];
            }
        }
        if ($filterParams !== [] && isset($query['filter']) && is_array($query['filter'])) {
            $eq = [];
            $this->collectEqConditions($query['filter'], $eq);
            foreach ($eq as $fieldId => $value) {
                if (isset($filterParams[$fieldId]) && (is_scalar($value) || $value === null)) {
                    $params[$filterParams[$fieldId]] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Collect the literal-valued equality conditions a flat AND tree exposes —
     * the only filter shape we can faithfully push down to a single query param
     * each. Other operators (or/not/gt/contains/value_expression) are skipped:
     * the external API can't express them through this mapping, so they degrade
     * to no-op rather than being silently mistranslated.
     *
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $out  field_id => value (by reference)
     */
    private function collectEqConditions(array $expr, array &$out): void
    {
        $op = $expr['op'] ?? null;

        if ($op === 'and') {
            foreach ($expr['conditions'] ?? [] as $cond) {
                if (is_array($cond)) {
                    $this->collectEqConditions($cond, $out);
                }
            }

            return;
        }

        if ($op === 'eq' && ! empty($expr['field_id']) && array_key_exists('value', $expr)) {
            $out[$expr['field_id']] = $expr['value'];
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function externalPathFor(array $source, string $fieldId): ?string
    {
        foreach ($source['field_map'] ?? [] as $entry) {
            if (($entry['field_id'] ?? null) === $fieldId) {
                return $entry['external_path'] ?? null;
            }
        }

        return null;
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
