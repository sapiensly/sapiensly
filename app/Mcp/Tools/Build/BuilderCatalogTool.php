<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request as BuilderRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Base for the read-only "what can I build" catalog tools. Each delegates to the
 * matching builder tool in App\Ai\Tools\Builder (the single source of truth for
 * the component/field/action/trigger/step catalogs), so the MCP catalog can
 * never drift from what the builder's own LLM is told.
 */
abstract class BuilderCatalogTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    /** @var class-string the Laravel\Ai builder tool to delegate to */
    protected const BUILDER_TOOL = '';

    public function handle(Request $request): Response
    {
        return Response::text($this->builderTool($request)->handle(new BuilderRequest($this->arguments($request))));
    }

    /**
     * The builder tool instance to delegate to. Override when the tool needs
     * request context (e.g. the caller's organization) instead of the container
     * default.
     */
    protected function builderTool(Request $request): object
    {
        return app(static::BUILDER_TOOL);
    }

    /**
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        return [];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
