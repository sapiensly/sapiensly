<?php

namespace App\Mcp\Tools\Integrations;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Enums\Visibility;
use App\Mcp\Tools\Integrations\Concerns\PresentsTool;
use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\ToolConfigService;
use App\Support\Tools\ToolConfigRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a tool (a single connector operation an agent can call). Pick a type (mcp, rest_api, database, group) and pass the type-specific config — call get_tool on an existing tool or tools_reference to learn the config shape. A connected http/database tool references an integration via config.integration_id (create it first with create_integration). The tool is created as a draft; update_tool activates it. Sensitive config (auth, credentials) is encrypted at rest.')]
class CreateToolTool extends SapiensTool
{
    use PresentsTool;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', Tool::class)) {
            return Response::error('You do not have permission to create tools.');
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (ToolType $t): string => $t->value, ToolType::creatable()))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'config' => ['nullable', 'array'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
            ...ToolConfigRules::forType($request->get('type'), $user),
        ]);

        $type = ToolType::from($validated['type']);
        $config = $validated['config'] ?? [];

        $configService = app(ToolConfigService::class);
        if ($configService->hasSensitiveFields($type)) {
            $config = $configService->encryptConfig($type, $config);
        }

        $tool = Tool::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'type' => $type,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'config' => $config,
            'status' => AgentStatus::Draft,
        ]);

        if ($type === ToolType::Group && array_key_exists('tool_ids', $validated)) {
            foreach (array_values($validated['tool_ids'] ?? []) as $index => $toolId) {
                $tool->groupItems()->create(['tool_id' => $toolId, 'order' => $index]);
            }
        }

        return Response::json($this->toolPayload($tool));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_map(fn (ToolType $t): string => $t->value, ToolType::creatable()))->description('Tool type: mcp, rest_api, database, or group.')->required(),
            'name' => $schema->string()->description('The tool name.')->required(),
            'description' => $schema->string()->description('What the operation does (helps agents pick it).'),
            'config' => $schema->object()->description('Type-specific config (e.g. rest_api: { base_url, method, auth_type } or { integration_id, method, path }; database: { driver, database, query_template }). See get_tool / tools_reference.'),
            'tool_ids' => $schema->array()->description('For type=group: the member tool ids, in order.'),
        ];
    }
}
