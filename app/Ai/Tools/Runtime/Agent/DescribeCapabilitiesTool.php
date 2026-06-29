<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Models\App;
use App\Services\Records\AppDataOverview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent read tool (builder power #3). Gives the model the big picture of
 * the data it may read before it queries: the objects (with live record counts),
 * their fields, and the relation graph between them, so it never faces a blank
 * box (vision §8 legibility). Lists only the granted objects; an ungranted object
 * is invisible here. Source-agnostic: internal and connected objects look
 * identical to the agent. Reuses the shared AppDataOverview digest so it never
 * drifts from the builder schema view or the MCP describe_app_data tool.
 */
class DescribeCapabilitiesTool implements Tool
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $readableObjectIds
     */
    public function __construct(
        private App $appModel,
        private array $manifest,
        private array $readableObjectIds,
        private AppDataOverview $overview,
    ) {}

    public function name(): string
    {
        return 'describe_capabilities';
    }

    public function description(): string
    {
        return <<<'DESC'
Describe the data this assistant can read — call this first to learn what you can
query before calling query_object or aggregate_object. Returns { objects: [{ id,
slug, name, description?, source, record_count, fields: [{ id, slug, name, type,
derived, target_object_id?, cardinality? }] }], relations: [{ from_object_id,
to_object_id, kind, cardinality, from_field_slug }], workflows_by_object }.
`source` is "internal" or "connected" (an external system) — query them the same
way. `record_count` is null for connected objects.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $digest = $this->overview->compact($this->appModel, $this->manifest, $this->readableObjectIds);

        return json_encode($digest, JSON_THROW_ON_ERROR);
    }
}
