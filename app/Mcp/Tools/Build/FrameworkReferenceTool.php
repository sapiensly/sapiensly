<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\FrameworkReferenceTool as BuilderTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Detailed authoring reference for building apps. Call with no topic to list topics (forms, workflows, derived_fields, expressions, design, verification, connected_objects, example), then call again with a topic.')]
class FrameworkReferenceTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;

    /**
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        return ['topic' => (string) $request->get('topic', '')];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('The reference topic to read; omit to list available topics.'),
        ];
    }
}
