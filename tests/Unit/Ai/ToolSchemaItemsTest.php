<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;

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
