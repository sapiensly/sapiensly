<?php

use App\Ai\Tools\Platform\McpBridgeTool;
use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Ai\Tools\RuntimeToolFactory;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\WhoamiTool;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;

beforeEach(function () {
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
});

it('exposes safe MCP tools and withholds the destructive ones', function () {
    $names = collect(PlatformToolsFactory::for($this->owner))
        ->map(fn (ToolContract $t) => class_basename($t))
        ->all();

    // Safe set present.
    expect($names)->toContain('query_records', 'search_knowledge', 'create_record', 'create_knowledge_base', 'invoke_agent', 'list_apps');

    // Denylisted set absent.
    foreach (PlatformToolsFactory::DENYLIST as $denied) {
        expect($names)->not->toContain($denied);
    }
});

it('gives every platform tool a unique SDK name', function () {
    $names = collect(PlatformToolsFactory::for($this->owner))
        ->map(fn (ToolContract $t) => class_basename($t));

    expect($names->duplicates())->toBeEmpty();
});

it('drift guard: every registered MCP tool is either exposed or denylisted', function () {
    $exposed = collect(PlatformToolsFactory::for($this->owner))
        ->map(fn (ToolContract $t) => class_basename($t))
        ->all();

    foreach (SapiensServer::TOOLS as $class) {
        $name = (string) Str::of(class_basename($class))->beforeLast('Tool')->snake();
        expect(in_array($name, $exposed, true) || in_array($name, PlatformToolsFactory::DENYLIST, true))
            ->toBeTrue("MCP tool '{$name}' is neither exposed to agents nor denylisted — classify it.");
    }
});

it('runs the bridged MCP handler as the agent owner', function () {
    $bridge = new McpBridgeTool(WhoamiTool::class, $this->owner);

    $out = (string) $bridge->handle(new AiRequest([]));

    // whoami reflects the acting user — proves Auth was the owner during the call.
    expect($out)->toContain($this->owner->email);
    // Auth is restored afterwards (no leaked acting user).
    expect(auth()->user())->toBeNull();
});

it('delegates the schema and description to the wrapped MCP tool', function () {
    $bridge = new McpBridgeTool(WhoamiTool::class, $this->owner);
    $mcp = new WhoamiTool;

    expect((string) $bridge->description())->toBe((string) $mcp->description());
});

it('lets a user tool win over a platform tool of the same name', function () {
    $stub = new class implements ToolContract
    {
        public function description(): string
        {
            return 'mine';
        }

        public function handle(AiRequest $request): string
        {
            return 'mine';
        }

        public function schema($schema): array
        {
            return [];
        }
    };

    $existing = [RuntimeToolFactory::named('list_apps', $stub)];

    $merged = PlatformToolsFactory::merge($existing, $this->owner);

    $listApps = array_values(array_filter($merged, fn (ToolContract $t) => class_basename($t) === 'list_apps'));
    expect($listApps)->toHaveCount(1);
    expect((string) $listApps[0]->description())->toBe('mine'); // the user's tool, not the bridge
});
