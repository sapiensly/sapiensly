<?php

namespace App\Ai\Tools\Builder\Concerns;

use App\Services\Manifest\ManifestSchemaCatalog;

trait EnrichesCatalogEntries
{
    /**
     * Attach the machine-readable schema contract to each catalog entry: `params`
     * (required/optional property names + allowed enum values), `example` (a
     * fill-in skeleton) and `definition` (the $defs name to drill into via
     * get_manifest_schema). The prose `description`/`props` stays as the human
     * summary; this adds the detail a model otherwise has to guess. Entries with
     * no matching schema definition are returned unchanged.
     *
     * @param  'field'|'component'|'action'|'step'|'trigger'  $category
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    protected function withSchema(string $category, array $entries): array
    {
        $catalog = app(ManifestSchemaCatalog::class);

        return array_map(function (array $entry) use ($category, $catalog): array {
            $type = $entry['type'] ?? null;
            if (! is_string($type)) {
                return $entry;
            }

            $params = $catalog->params($category, $type);
            if ($params === null) {
                return $entry;
            }

            $entry['params'] = $params;
            $entry['definition'] = $catalog->definitionName($category, $type);
            $entry['example'] = $catalog->example($category, $type);

            return $entry;
        }, $entries);
    }
}
