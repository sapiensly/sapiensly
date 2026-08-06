<?php

use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * Gemini's function-calling validator rejects any array schema without an
 * `items` field ("GenerateContentRequest.tools[N].function_declarations[M]
 * .parameters.properties[x].items: missing field" — observed live, it killed
 * a landing build on gemini-3.6-flash at the first request). JSON Schema
 * treats `items` as optional, so nothing else catches the omission. This
 * walks every agent-facing tool's compiled schema and blocks untyped arrays.
 */
function collectUntypedArrays(array $node, string $path, array &$violations): void
{
    if (($node['type'] ?? null) === 'array' && ! array_key_exists('items', $node)) {
        $violations[] = $path;
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            collectUntypedArrays($value, "{$path}.{$key}", $violations);
        }
    }
}

it('every agent tool schema declares items on its arrays', function () {
    // No app_path(): the Unit suite runs without a booted Laravel app.
    $base = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Ai'.DIRECTORY_SEPARATOR.'Tools';
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relative = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $classes[] = 'App\\Ai\\Tools\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }
    }

    $factory = new JsonSchemaTypeFactory;
    $violations = [];
    $checked = 0;

    foreach ($classes as $class) {
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->implementsInterface(Tool::class)) {
            continue;
        }

        try {
            /** @var Tool $tool */
            $tool = $reflection->newInstanceWithoutConstructor();
            $properties = $tool->schema($factory);
            $compiled = (new ObjectSchema($properties))->toSchema();
        } catch (Throwable) {
            // A schema() that needs constructor state (e.g. McpServerTool maps
            // an injected definition) can't be compiled statelessly — skip.
            continue;
        }

        $checked++;
        collectUntypedArrays($compiled, $class, $violations);
    }

    expect($checked)->toBeGreaterThanOrEqual(15)
        ->and($violations)->toBe([]);
});

/**
 * The MCP surface has the same defect and the same cause.
 *
 * An MCP tool's schema is read by whatever model the CLIENT runs, so a bare
 * array here fails exactly as it does above the moment that client is Gemini —
 * including this platform's own agents, which reach external MCP servers
 * through `McpServerTool`.
 *
 * This started as a ratchet over 31 known-untyped properties, on the theory
 * that visible debt beats a chat message. They were all typed the same day, so
 * the list is gone and the rule is simply the rule.
 */
it('every MCP tool schema declares items on its arrays', function () {
    $base = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Mcp'.DIRECTORY_SEPARATOR.'Tools';
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relative = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $classes[] = 'App\\Mcp\\Tools\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }
    }

    $violations = [];
    $checked = 0;

    foreach ($classes as $class) {
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(McpTool::class)) {
            continue;
        }

        try {
            /** @var McpTool $tool */
            $tool = $reflection->newInstanceWithoutConstructor();
            // The same compilation Laravel MCP does in Tool::toArray().
            $compiled = JsonSchemaFactory::object($tool->schema(...))->toArray();
        } catch (Throwable) {
            continue;
        }

        $checked++;
        collectUntypedArrays($compiled, str_replace('App\\Mcp\\Tools\\', '', $class), $violations);
    }

    expect($checked)->toBeGreaterThanOrEqual(40)
        ->and($violations)->toBe([]);
});
