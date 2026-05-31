<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Wraps an inner tool so it can be exposed under a unique class name.
 *
 * The Laravel AI SDK derives the LLM-facing tool name from class_basename(),
 * NOT from a name() method — so two instances of the same wrapper class
 * (e.g. many DynamicTool / McpServerTool) collide and the provider rejects the
 * request with "duplicate tool name". RuntimeToolFactory eval-defines a small
 * uniquely-named subclass of this base per tool; the inner tool carries the
 * actual schema/description/execution logic.
 */
class RuntimeTool implements ToolContract
{
    public function __construct(protected ToolContract $inner) {}

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->inner->handle($request);
    }
}
