<?php

namespace App\Ai\Tools\Runtime\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent read tool (builder power #3). Enumerates the objects this app's
 * agent may read, with their fields, so the model never faces a blank box — it
 * can discover what it can query before querying (vision §8 legibility). Lists
 * only the granted objects; an ungranted object is invisible here. Source-
 * agnostic: internal and connected objects look identical to the agent.
 */
class DescribeCapabilitiesTool implements Tool
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $readableObjectIds
     */
    public function __construct(
        private array $manifest,
        private array $readableObjectIds,
    ) {}

    public function name(): string
    {
        return 'describe_capabilities';
    }

    public function description(): string
    {
        return <<<'DESC'
List the data objects this assistant can read, with their fields. Call this
first to learn what you can query before calling query_object or
aggregate_object. Returns { objects: [{ id, slug, name, description?, source,
fields: [{ id, slug, name, type }] }] }. `source` is "internal" or "connected"
(an external system) — query them the same way.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $objects = [];
        foreach ($this->manifest['objects'] ?? [] as $object) {
            if (! in_array($object['id'] ?? null, $this->readableObjectIds, true)) {
                continue;
            }

            $objects[] = [
                'id' => $object['id'],
                'slug' => $object['slug'] ?? null,
                'name' => $object['name'] ?? null,
                'description' => $object['description'] ?? null,
                'source' => $object['source']['type'] ?? 'internal',
                'fields' => array_map(fn ($f) => [
                    'id' => $f['id'] ?? null,
                    'slug' => $f['slug'] ?? null,
                    'name' => $f['name'] ?? null,
                    'type' => $f['type'] ?? null,
                ], $object['fields'] ?? []),
            ];
        }

        return json_encode(['objects' => $objects], JSON_THROW_ON_ERROR);
    }
}
