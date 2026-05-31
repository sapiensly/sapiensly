<?php

use App\Ai\Tools\RuntimeToolFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request;

function fakeInnerTool(string $reply): ToolContract
{
    return new class($reply) implements ToolContract
    {
        public function __construct(private string $reply) {}

        public function description(): string
        {
            return 'desc:'.$this->reply;
        }

        public function schema(JsonSchema $schema): array
        {
            return ['x' => $schema->string()];
        }

        public function handle(Request $request): string
        {
            return $this->reply;
        }
    };
}

it('names each tool after a unique class basename so the provider sees distinct names', function () {
    $a = RuntimeToolFactory::named('crm_concepts', fakeInnerTool('a'));
    $b = RuntimeToolFactory::named('crm firm summary', fakeInnerTool('b'));

    // The SDK uses class_basename() as the tool name — these must differ.
    expect(class_basename($a))->toBe('crm_concepts')
        ->and(class_basename($b))->toBe('crm_firm_summary')
        ->and(class_basename($a))->not->toBe(class_basename($b));
});

it('delegates description, schema and handle to the inner tool', function () {
    $tool = RuntimeToolFactory::named('do_thing', fakeInnerTool('done'));

    expect((string) $tool->description())->toBe('desc:done')
        ->and((string) $tool->handle(new Request([])))->toBe('done')
        ->and(array_keys($tool->schema(new JsonSchemaTypeFactory)))->toBe(['x']);
});

it('avoids PHP reserved words as class names', function () {
    $tool = RuntimeToolFactory::named('list', fakeInnerTool('x'));

    expect(class_basename($tool))->toBe('list_tool');
});

it('reuses the same class for the same name without redeclaring', function () {
    $a = RuntimeToolFactory::named('shared_name', fakeInnerTool('a'));
    $b = RuntimeToolFactory::named('shared_name', fakeInnerTool('b'));

    expect($a::class)->toBe($b::class);
});
